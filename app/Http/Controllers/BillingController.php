<?php

namespace App\Http\Controllers;

use App\Jobs\CreateWhishCheckout;
use App\Models\CashSession;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\BillingDesk;
use App\Services\Customers\CustomerRecords;
use App\Services\Integrations\IntegrationSettings;
use App\Services\Integrations\WhatsAppBusinessClient;
use App\Services\Integrations\WhatsAppDesk;
use App\Services\Payments\PaymentDesk;
use App\Services\Quotes\DocumentDriver;
use App\Services\Quotes\DocumentHtml;
use App\Services\Settings\CommercialSettings;
use App\Support\Access\AccessGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class BillingController extends Controller
{
    public function index(Request $request, AccessGate $access, BillingDesk $billing, CommercialSettings $commercial, PaymentDesk $payments): Response
    {
        $actor = $access->effective($request);
        abort_unless($actor instanceof User, 403);
        $seeAmounts = $access->allows($actor, 'billing.view_amounts');
        $balanceOnly = ! $seeAmounts && $access->allows($actor, 'invoices.view_balance');
        $session = CashSession::query()->where('user_id', $actor->id)->whereIn('status', ['open', 'pending_close'])->latest('id')->first();

        return Inertia::render('Billing/Index', [
            'invoices' => $balanceOnly ? [] : $billing->visibleInvoices($actor)->with('customer:id,name_en,name_ar,whatsapp_consent,whatsapp_e164')->latest('id')->limit(40)->get()->map(fn (Invoice $invoice) => $this->row($invoice, $seeAmounts))->values(),
            'payments' => $seeAmounts ? Payment::query()->with('customer:id,name_en,name_ar,whatsapp_e164,whatsapp_consent')->latest('id')->limit(20)->get()->map(fn (Payment $payment) => [
                'id' => $payment->id,
                'number' => $payment->number,
                'status' => $payment->status,
                'method' => $payment->method,
                'customer' => $payment->customer?->name_en,
                'customer_ar' => $payment->customer?->name_ar,
                'amount' => (string) $payment->amount,
                'tender' => (string) $payment->tender_amount,
                'currency' => $payment->currency,
                'whatsapp' => $payments->whatsapp($payment),
            ])->values() : [],
            'can_issue' => $access->allows($actor, 'invoices.issue'),
            'can_write_off' => $access->allows($actor, 'payments.write_off'),
            'can_record' => $access->allows($actor, 'payments.record'),
            'can_request' => $access->allows($actor, 'payments.request'),
            'can_reverse' => $access->allows($actor, 'payments.reverse'),
            'see_amounts' => $seeAmounts,
            'balance_only' => $balanceOnly,
            'vat' => (string) $commercial->get('vat_percent'),
            'whish_live' => app(IntegrationSettings::class)->runtime('whish')['live'],
            'whatsapp_live' => app(WhatsAppBusinessClient::class)->ready(),
            'cash_session' => $session instanceof CashSession ? [
                'id' => $session->id,
                'kind' => $session->kind,
                'status' => $session->status,
                'expected' => (string) $session->expected,
                'counted' => $session->counted === null ? null : (string) $session->counted,
            ] : null,
        ]);
    }

    public function lookup(Request $request, CustomerRecords $customers, AccessGate $access): JsonResponse
    {
        $actor = $access->effective($request);
        abort_unless($actor instanceof User, 403);
        $term = trim((string) $request->string('q'));

        if (mb_strlen($term) < 2) {
            return response()->json([]);
        }

        $digits = preg_replace('/\D+/', '', $term) ?? '';
        $like = '%'.$term.'%';
        $rows = $customers->query($actor)
            ->where(function ($inner) use ($like, $digits): void {
                $inner->where('name_en', 'like', $like)->orWhere('name_ar', 'like', $like)->orWhere('account_no', 'like', $like);
                if ($digits !== '') {
                    $inner->orWhereHas('phones', fn ($phones) => $phones->where('phone_e164', 'like', '%'.$digits.'%'));
                }
            })
            ->limit(8)
            ->get(['id', 'name_en', 'name_ar', 'account_no'])
            ->map(fn (Customer $customer) => [
                'id' => $customer->id,
                'name_en' => $customer->name_en,
                'name_ar' => $customer->name_ar,
                'account_no' => $customer->account_no,
            ]);

        return response()->json($rows);
    }

    public function run(Request $request, AccessGate $access, BillingDesk $billing): RedirectResponse
    {
        $actor = $access->effective($request);
        abort_unless($actor instanceof User, 403);
        $count = $billing->run($actor, $request);

        return back()->with('status', __('ui.billing.run').' '.$count);
    }

    public function credit(Request $request, Invoice $invoice, AccessGate $access, BillingDesk $billing): RedirectResponse
    {
        $actor = $access->effective($request);
        abort_unless($actor instanceof User, 403);
        $data = $request->validate(['amount' => ['required', 'numeric', 'min:0.01']]);
        $billing->credit($invoice, (string) $data['amount'], $actor, $request);

        return back()->with('status', __('ui.billing.saved'));
    }

    public function void(Request $request, Invoice $invoice, AccessGate $access, BillingDesk $billing): RedirectResponse
    {
        $actor = $access->effective($request);
        abort_unless($actor instanceof User, 403);
        $data = $request->validate(['reason' => ['required', 'string', 'max:240']]);
        $billing->void($invoice, $data['reason'], $actor, $request);

        return back()->with('status', __('ui.billing.saved'));
    }

    public function writeOff(Request $request, Invoice $invoice, AccessGate $access, BillingDesk $billing): RedirectResponse
    {
        $actor = $access->effective($request);
        abort_unless($actor instanceof User, 403);
        $billing->requestWriteOff($invoice, $actor, $request);

        return back()->with('status', __('ui.billing.approval_needed'));
    }

    public function print(Request $request, Invoice $invoice, AccessGate $access, BillingDesk $billing, DocumentHtml $html, DocumentDriver $driver, AuditLogger $audit): HttpResponse
    {
        $actor = $access->effective($request);
        abort_unless($actor instanceof User, 403);
        abort_unless($access->allows($actor, 'billing.view_amounts'), 403);
        abort_unless($billing->visibleInvoices($actor)->whereKey($invoice->id)->exists(), 404);
        $locale = $request->query('locale') === 'ar' ? 'ar' : 'en';
        $printed = $driver->print($html->invoice($invoice, $locale));
        $audit->record('billing.printed', $actor, ['invoice_id' => $invoice->id, 'customer_id' => $invoice->customer_id, 'number' => $invoice->number], $request);
        $extension = str_contains($printed['mime'], 'pdf') ? 'pdf' : 'html';

        return response($printed['body'], 200, [
            'Content-Type' => $printed['mime'],
            'Content-Disposition' => 'inline; filename="'.$invoice->number.'.'.$extension.'"',
        ]);
    }

    public function refund(Request $request, AccessGate $access, BillingDesk $billing): RedirectResponse
    {
        $actor = $access->effective($request);
        abort_unless($actor instanceof User, 403);
        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
        ]);
        $billing->requestRefund((int) $data['customer_id'], (string) $data['amount'], $actor, $request);

        return back()->with('status', __('ui.billing.approval_needed'));
    }

    public function whish(Request $request, Invoice $invoice, AccessGate $access, BillingDesk $billing, IntegrationSettings $settings): RedirectResponse
    {
        $actor = $access->effective($request);
        abort_unless($actor instanceof User, 403);
        abort_unless($billing->visibleInvoices($actor)->whereKey($invoice->id)->exists(), 404);

        if (! $settings->runtime('whish')['live'] || ! in_array($invoice->status, ['issued', 'partially_paid', 'overdue'], true) || $invoice->type !== 'invoice') {
            return back()->withErrors(['whish' => __('ui.payments.whish_off')]);
        }

        try {
            CreateWhishCheckout::dispatch($invoice->id, $actor->id);
        } catch (Throwable) {
            return back()->withErrors(['whish' => __('ui.integrations.test_failed')]);
        }

        return back()->with('status', __('ui.payments.whish_ready'));
    }

    public function whatsapp(Request $request, Invoice $invoice, AccessGate $access, BillingDesk $billing, WhatsAppDesk $whatsapp): RedirectResponse
    {
        $actor = $access->effective($request);
        abort_unless($actor instanceof User, 403);
        abort_unless($billing->visibleInvoices($actor)->whereKey($invoice->id)->exists(), 404);
        $invoice->loadMissing('customer');
        $customer = $invoice->customer;

        if (! $customer instanceof Customer) {
            return back()->withErrors(['whatsapp' => __('ui.whatsapp.off')]);
        }

        try {
            $result = $whatsapp->queue($customer, 'payment', [
                (string) $customer->name_en,
                (string) $invoice->number,
                (string) $invoice->total,
            ], $actor);
        } catch (Throwable) {
            return back()->withErrors(['whatsapp' => __('ui.whatsapp.failed')]);
        }

        return match ($result) {
            'queued' => back()->with('status', __('ui.whatsapp.sent')),
            'no_consent' => back()->withErrors(['whatsapp' => __('ui.whatsapp.no_consent')]),
            default => back()->withErrors(['whatsapp' => __('ui.whatsapp.off')]),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Invoice $invoice, bool $seeAmounts): array
    {
        return [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'type' => $invoice->type,
            'status' => $invoice->status,
            'customer' => $invoice->customer?->name_en,
            'customer_ar' => $invoice->customer?->name_ar,
            'total' => $seeAmounts ? (string) $invoice->total : null,
            'lbp' => $seeAmounts ? (string) $invoice->lbp_total : null,
            'tax' => $seeAmounts ? (string) $invoice->tax : null,
            'currency' => $invoice->currency,
            'exchange_rate' => $seeAmounts && (float) $invoice->exchange_rate > 0 ? (string) $invoice->exchange_rate : null,
            'customer_id' => $invoice->customer_id,
            'whish_url' => $invoice->whish_url,
            'whatsapp_consent' => (bool) $invoice->customer?->whatsapp_consent && filled($invoice->customer?->whatsapp_e164),
        ];
    }
}
