<?php

namespace App\Http\Controllers;

use App\Models\CashSession;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Payments\PaymentDesk;
use App\Services\Quotes\DocumentDriver;
use App\Services\Quotes\DocumentHtml;
use App\Support\Access\AccessGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;

class PaymentController extends Controller
{
    public function store(Request $request, AccessGate $access, PaymentDesk $payments): RedirectResponse
    {
        $actor = $access->effective($request);
        abort_unless($actor instanceof User, 403);
        $data = $this->validated($request);
        $payments->record($actor, $data, $request, 'desk');

        return back()->with('status', __('ui.payments.saved'));
    }

    public function confirm(Request $request, Payment $payment, AccessGate $access, PaymentDesk $payments): RedirectResponse
    {
        $actor = $access->effective($request);
        abort_unless($actor instanceof User, 403);
        $payments->confirm($payment, $actor, $request);

        return back()->with('status', __('ui.payments.saved'));
    }

    public function reverse(Request $request, Payment $payment, AccessGate $access, PaymentDesk $payments): RedirectResponse
    {
        $actor = $access->effective($request);
        abort_unless($actor instanceof User, 403);
        $payments->requestReverse($payment, $actor, $request);

        return back()->with('status', __('ui.payments.approval_needed'));
    }

    public function close(Request $request, CashSession $session, AccessGate $access, PaymentDesk $payments): RedirectResponse
    {
        $actor = $access->effective($request);
        abort_unless($actor instanceof User, 403);
        $data = $request->validate([
            'counted' => ['required', 'numeric', 'min:0'],
            'password' => ['nullable', 'string'],
        ]);
        $payments->close($session, (string) $data['counted'], $actor, $request);

        return back()->with('status', __('ui.payments.closed'));
    }

    public function receipt(Request $request, Payment $payment, AccessGate $access, PaymentDesk $payments, DocumentHtml $html, DocumentDriver $driver, AuditLogger $audit): HttpResponse
    {
        $actor = $access->effective($request);
        abort_unless($actor instanceof User, 403);
        abort_unless($access->allows($actor, 'billing.view_amounts') || $access->allows($actor, 'payments.collect'), 403);
        $locale = $request->query('locale') === 'ar' ? 'ar' : 'en';
        $printed = $driver->print($html->receipt($payment, $locale));
        $audit->record('payment.printed', $actor, ['payment_id' => $payment->id, 'customer_id' => $payment->customer_id, 'number' => $payment->number], $request);
        $extension = str_contains($printed['mime'], 'pdf') ? 'pdf' : 'html';

        return response($printed['body'], 200, [
            'Content-Type' => $printed['mime'],
            'Content-Disposition' => 'inline; filename="'.$payment->number.'.'.$extension.'"',
        ]);
    }

    public function statement(Request $request, Customer $customer, AccessGate $access, PaymentDesk $payments, DocumentHtml $html, DocumentDriver $driver, AuditLogger $audit): HttpResponse
    {
        $actor = $access->effective($request);
        abort_unless($actor instanceof User, 403);
        abort_unless($access->allows($actor, 'billing.view_amounts'), 403);
        $from = $request->query('from');
        $to = $request->query('to');
        $locale = $request->query('locale') === 'ar' ? 'ar' : 'en';
        $rows = $payments->statement($customer, is_string($from) ? $from : null, is_string($to) ? $to : null);
        $printed = $driver->print($html->statement($customer, $rows, $locale, is_string($from) ? $from : null, is_string($to) ? $to : null));
        $audit->record('payment.statement', $actor, ['customer_id' => $customer->id, 'from' => $from, 'to' => $to], $request);

        return response($printed['body'], 200, [
            'Content-Type' => $printed['mime'],
            'Content-Disposition' => 'inline; filename="statement-'.$customer->account_no.'.html"',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['required', 'in:USD,LBP'],
            'method' => ['required', 'in:cash,whish,omt,bank_transfer,card,cheque'],
            'reference' => ['nullable', 'string', 'max:80'],
            'invoice_id' => ['nullable', 'integer', 'exists:invoices,id'],
            'password' => ['nullable', 'string'],
        ]);
    }
}
