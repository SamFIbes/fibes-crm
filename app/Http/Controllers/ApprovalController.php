<?php

namespace App\Http\Controllers;

use App\Models\Approval;
use App\Models\ApprovalPolicy;
use App\Models\User;
use App\Services\Approvals\ApprovalDesk;
use App\Support\Access\AccessGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ApprovalController extends Controller
{
    public function index(Request $request, ApprovalDesk $approvals, AccessGate $access): Response
    {
        $actor = $this->actor($request, $access);
        abort_unless($access->allows($actor, 'approvals.view') || $access->allows($actor, 'approvals.decide') || $access->allows($actor, 'approvals.request'), 403);

        return Inertia::render('Approvals/Index', [
            'items' => $approvals->inbox($actor),
            'waiting' => $approvals->waitingFor($actor),
            'policies' => $access->allows($actor, 'approvals.manage')
                ? ApprovalPolicy::query()->orderBy('name_en')->get(['id', 'key', 'name_en', 'name_ar', 'timeout_hours', 'low_risk'])
                : [],
            'can_decide' => $access->allows($actor, 'approvals.decide'),
            'can_manage' => $access->allows($actor, 'approvals.manage'),
        ]);
    }

    public function store(Request $request, ApprovalDesk $approvals, AccessGate $access): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        abort_unless($access->allows($actor, 'approvals.request'), 403);
        $data = $request->validate([
            'type' => ['required', 'string', 'max:40'],
            'summary' => ['required', 'string', 'max:180'],
            'percent' => ['nullable', 'numeric', 'min:0'],
            'amount' => ['nullable', 'numeric', 'min:0'],
        ]);
        $approvals->open($actor, $data['type'], 'request', null, [
            'percent' => $data['percent'] ?? null,
            'amount' => $data['amount'] ?? null,
        ], $data['summary'], $request);

        return back()->with('status', __('ui.approvals.sent'));
    }

    public function approve(Request $request, Approval $approval, ApprovalDesk $approvals, AccessGate $access): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        abort_unless($access->allows($actor, 'approvals.decide'), 403);
        $data = $request->validate(['comment' => ['nullable', 'string', 'max:500']]);
        $approvals->approve($approval, $actor, $data['comment'] ?? null, $request);

        return back()->with('status', __('ui.approvals.approved_title'));
    }

    public function reject(Request $request, Approval $approval, ApprovalDesk $approvals, AccessGate $access): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        abort_unless($access->allows($actor, 'approvals.decide'), 403);
        $data = $request->validate(['comment' => ['nullable', 'string', 'max:500']]);
        $approvals->reject($approval, $actor, $data['comment'] ?? null, $request);

        return back()->with('status', __('ui.approvals.rejected_title'));
    }

    public function bulk(Request $request, ApprovalDesk $approvals, AccessGate $access): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        abort_unless($access->allows($actor, 'approvals.decide'), 403);
        $data = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);
        $count = $approvals->bulk($data['ids'], $actor, $request);

        return back()->with('status', __('ui.approvals.bulk_done', ['count' => $count]));
    }

    public function delegate(Request $request, ApprovalDesk $approvals, AccessGate $access): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        abort_unless($access->allows($actor, 'approvals.decide'), 403);
        $data = $request->validate([
            'delegate_id' => ['required', 'integer', 'exists:users,id'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date'],
        ]);
        $delegate = User::query()->findOrFail($data['delegate_id']);
        $approvals->delegate($actor, $delegate, $data['starts_on'], $data['ends_on'], $actor, $request);

        return back()->with('status', __('ui.approvals.delegated'));
    }

    private function actor(Request $request, AccessGate $access): User
    {
        $actor = $access->effective($request);
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
