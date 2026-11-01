<?php

namespace App\Http\Controllers;

use App\Models\CollectionCase;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Billing\BillingDesk;
use App\Services\Collections\CollectionsDesk;
use App\Support\Access\AccessGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CollectionController extends Controller
{
    public function index(Request $request, AccessGate $access, CollectionsDesk $collections): Response
    {
        $actor = $access->effective($request);
        abort_unless($actor instanceof User, 403);
        $cases = CollectionCase::query()->with('customer:id,name_en,name_ar')->whereNull('archived_at')->latest('id')->limit(80)->get();

        return Inertia::render('Collections/Index', [
            'cases' => $cases->map(fn (CollectionCase $case) => [
                'id' => $case->id,
                'customer_id' => $case->customer_id,
                'customer' => $case->customer?->name_en,
                'customer_ar' => $case->customer?->name_ar,
                'stage' => $case->stage,
                'amount_due' => (string) $case->amount_due,
                'bucket' => $collections->bucket($case),
                'promise' => $case->promise_to_pay_date?->format('d/m/Y'),
                'invoice_id' => $case->invoice_id,
            ])->values(),
            'renewals' => $collections->renewals(),
            'can_suspend' => $access->allows($actor, 'services.suspend'),
            'can_request' => $access->allows($actor, 'services.request_suspend'),
            'can_write_off' => $access->allows($actor, 'payments.write_off'),
        ]);
    }

    public function promise(Request $request, CollectionCase $case, AccessGate $access, CollectionsDesk $collections): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        $data = $request->validate([
            'date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01'],
        ]);
        $collections->promise($case, $data['date'], (string) $data['amount'], $actor, $request);

        return back()->with('status', __('ui.collections.saved'));
    }

    public function assign(Request $request, CollectionCase $case, AccessGate $access, CollectionsDesk $collections): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        $data = $request->validate(['user_id' => ['required', 'integer', 'exists:users,id']]);
        $collections->assign($case, (int) $data['user_id'], $actor, $request);

        return back()->with('status', __('ui.collections.saved'));
    }

    public function suspend(Request $request, CollectionCase $case, AccessGate $access, CollectionsDesk $collections): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        $collections->suspend($case, $actor, $request);

        return back()->with('status', __('ui.collections.saved'));
    }

    public function terminate(Request $request, CollectionCase $case, AccessGate $access, CollectionsDesk $collections): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        $collections->terminate($case, $actor, $request);

        return back()->with('status', __('ui.collections.saved'));
    }

    public function reactivate(Request $request, CollectionCase $case, AccessGate $access, CollectionsDesk $collections): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        $collections->markReactivated($case, $actor, $request);

        return back()->with('status', __('ui.collections.saved'));
    }

    public function writeOff(Request $request, CollectionCase $case, AccessGate $access, BillingDesk $billing): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        $invoice = $case->invoice_id ? Invoice::query()->find($case->invoice_id) : null;
        abort_unless($invoice instanceof Invoice, 404);
        $billing->requestWriteOff($invoice, $actor, $request);

        return back()->with('status', __('ui.billing.approval_needed'));
    }

    public function exempt(Request $request, Customer $customer, AccessGate $access, CollectionsDesk $collections): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        $data = $request->validate([
            'kind' => ['required', 'in:vip,corporate,extension'],
            'until' => ['nullable', 'date'],
        ]);
        $collections->requestExemption($customer, $data['kind'], $data['until'] ?? null, $actor, $request);

        return back()->with('status', __('ui.collections.saved'));
    }

    public function run(Request $request, AccessGate $access, CollectionsDesk $collections): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        $count = $collections->run($actor);

        return back()->with('status', __('ui.collections.ran').' '.$count);
    }

    private function actor(Request $request, AccessGate $access): User
    {
        $actor = $access->effective($request);
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
