<?php

namespace App\Http\Controllers;

use App\Models\CashSession;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\BillingDesk;
use App\Services\Payments\PaymentDesk;
use App\Services\Quotes\DocumentDriver;
use App\Services\Quotes\DocumentHtml;
use App\Services\Settings\CommercialSettings;
use App\Support\Access\AccessGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

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
            'invoices' => $balanceOnly ? [] : $billing->visibleInvoices($actor)->with('customer:id,name_en,name_ar')->latest('id')->limit(40)->get()->map(fn (Invoice $invoice) => $this->row($invoice, $seeAmounts))->values(),
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
            'cash_session' => $session instanceof CashSession ? [
                'id' => $session->id,
                'kind' => $session->kind,
                'status' => $session->status,
                'expected' => (string) $session->expected,
                'counted' => $session->counted === null ? null : (string) $session->counted,
            ] : null,
        ]);
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
            'customer_id' => $invoice->customer_id,
        ];
    }
}
