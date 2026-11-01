<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\RetentionCase;
use App\Models\User;
use App\Services\Retention\RetentionDesk;
use App\Support\Access\AccessGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RetentionController extends Controller
{
    public function index(Request $request, AccessGate $access, RetentionDesk $retention): Response
    {
        $actor = $this->actor($request, $access);

        return Inertia::render('Retention/Index', [
            'cases' => RetentionCase::query()->with('customer:id,name_en,name_ar')->whereNull('archived_at')->latest('id')->limit(80)->get()->map(fn (RetentionCase $case) => [
                'id' => $case->id,
                'customer' => $case->customer?->name_en,
                'customer_ar' => $case->customer?->name_ar,
                'stage' => $case->stage,
                'reason' => $case->reason,
                'score' => $case->customer instanceof Customer ? $retention->score($case->customer) : 100,
            ])->values(),
            'reasons' => $retention->reasonList(),
            'breakdown' => $retention->reasons(),
            'report' => $retention->saveRate(),
            'can_offer' => $access->allows($actor, 'retention.offer'),
            'can_request' => $access->allows($actor, 'retention.request') || $access->allows($actor, 'retention.offer'),
        ]);
    }

    public function offer(Request $request, RetentionCase $case, AccessGate $access, RetentionDesk $retention): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        $data = $request->validate([
            'kind' => ['required', 'in:free_month,discount,upgrade'],
            'months' => ['nullable', 'integer', 'min:1', 'max:24'],
            'percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
        ]);
        $retention->offer($case, $data, $actor, $request);

        return back()->with('status', __('ui.retention.saved'));
    }

    public function decline(Request $request, RetentionCase $case, AccessGate $access, RetentionDesk $retention): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        $data = $request->validate(['reason' => ['required', 'string', 'max:40']]);
        $retention->decline($case, $data['reason'], $actor, $request);

        return back()->with('status', __('ui.retention.saved'));
    }

    private function actor(Request $request, AccessGate $access): User
    {
        $actor = $access->effective($request);
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
