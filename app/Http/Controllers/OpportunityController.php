<?php

namespace App\Http\Controllers;

use App\Models\Area;
use App\Models\LossReason;
use App\Models\Opportunity;
use App\Models\Pipeline;
use App\Models\User;
use App\Models\WorkTask;
use App\Services\Opportunities\OpportunityDesk;
use App\Support\Access\AccessGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class OpportunityController extends Controller
{
    public function index(Request $request, OpportunityDesk $deals, AccessGate $access): Response
    {
        $actor = $this->actor($request, $access);
        Gate::authorize('viewAny', Opportunity::class);
        $pipelineKey = (string) $request->string('pipeline', 'retail');
        $pipeline = Pipeline::query()->where('key', $pipelineKey)->first()
            ?? Pipeline::query()->where('key', 'retail')->firstOrFail();
        $view = (string) $request->string('view', 'board');
        $query = $deals->visible($actor)->with(['customer:id,name_en,name_ar,account_no,status', 'stage', 'owner:id,name', 'pipeline'])
            ->where('pipeline_id', $pipeline->id);

        if ($request->filled('owner_id')) {
            $query->where('owner_id', $request->integer('owner_id'));
        }

        if ($request->filled('stage')) {
            $query->whereHas('stage', fn ($stage) => $stage->where('key', $request->string('stage')->toString()));
        }

        if ($request->filled('service')) {
            $query->where('service_type', $request->string('service')->toString());
        }

        if ($request->filled('area_id')) {
            $query->where('area_id', $request->integer('area_id'));
        }

        if ($request->integer('age') > 0) {
            $query->where('created_at', '<=', now()->subDays($request->integer('age')));
        }

        if ($request->filled('min_value')) {
            $query->where('mrc', '>=', $request->input('min_value'));
        }

        if ($request->string('saved')->toString() === 'mine') {
            $query->where('owner_id', $actor->id);
        } elseif ($request->string('saved')->toString() === 'won') {
            $query->whereHas('stage', fn ($stage) => $stage->where('is_won', true));
        } elseif ($request->string('saved')->toString() === 'lost') {
            $query->whereHas('stage', fn ($stage) => $stage->where('is_lost', true));
        }

        $rows = $query->latest('id')->limit(200)->get();

        if ($request->string('saved')->toString() === 'stale') {
            $rows = $rows->filter(function (Opportunity $deal) {
                $days = $deal->stage?->stale_after_days;
                $entered = $deal->stage_entered_at;

                return $days !== null && $entered !== null && $entered->copy()->addDays((int) $days)->lessThanOrEqualTo(now());
            })->values();
        }
        $open = $request->integer('deal');
        $detail = null;

        if ($open > 0) {
            $deal = $deals->visible($actor)->with(['history.stage', 'tasks', 'stage', 'customer', 'owner', 'pipeline'])->find($open);

            if ($deal instanceof Opportunity) {
                $detail = $deals->present($deal, $actor) + [
                    'history' => $deal->history->map(fn ($row) => [
                        'id' => $row->id,
                        'stage' => $row->stage?->key,
                        'seconds' => $row->seconds,
                    ])->values(),
                    'tasks' => $deal->tasks->map(fn (WorkTask $task) => [
                        'id' => $task->id,
                        'title' => $task->title,
                        'due_at' => $task->due_at?->timezone((string) config('app.timezone'))->format('Y-m-d H:i'),
                        'status' => $task->status,
                    ])->values(),
                ];
            }
        }

        return Inertia::render('Opportunities/Index', [
            'pipeline' => $pipeline->key,
            'pipelines' => Pipeline::query()->orderBy('id')->get(['key', 'name_en', 'name_ar']),
            'stages' => $pipeline->stages()->where('entity', 'opportunity')->get(['id', 'key', 'name_en', 'name_ar', 'is_won', 'is_lost']),
            'deals' => $rows->map(fn (Opportunity $deal) => $deals->present($deal, $actor))->values(),
            'funnel' => $deals->funnel($pipeline),
            'detail' => $detail,
            'view' => $view === 'list' ? 'list' : 'board',
            'saved' => $request->string('saved')->toString(),
            'reasons' => LossReason::query()->orderBy('name_en')->get(['id', 'key', 'name_en', 'name_ar']),
            'areas' => Area::query()->orderBy('name_en')->limit(300)->get(['id', 'name_en', 'name_ar']),
            'people' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'services' => array_keys(config('fibes.service_types')),
            'can_edit' => $access->allows($actor, 'opportunities.edit'),
            'can_close' => $access->allows($actor, 'opportunities.close'),
            'can_reassign' => $access->scopeFor($actor, 'opportunities.edit') === 'all',
            'tiles' => (string) config('fibes.coverage.tile_url'),
        ]);
    }

    public function move(Request $request, Opportunity $opportunity, OpportunityDesk $deals, AccessGate $access): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        Gate::authorize('update', $opportunity);
        $data = $request->validate([
            'stage' => ['required', 'string', 'max:40'],
            'coverage_note' => ['nullable', 'string', 'max:2000'],
            'loss_reason_id' => ['nullable', 'integer', 'exists:loss_reasons,id'],
            'override_reason' => ['nullable', 'string', 'max:500'],
            'competitor' => ['nullable', 'string', 'max:120'],
        ]);
        $deals->move($opportunity, $data['stage'], $actor, $request, $data);

        return back()->with('status', __('ui.opportunities.moved'));
    }

    public function reassign(Request $request, OpportunityDesk $deals, AccessGate $access): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        abort_unless($access->scopeFor($actor, 'opportunities.edit') === 'all', 403);
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:opportunities,id'],
            'assignee_id' => ['required', 'integer', 'exists:users,id'],
        ]);
        $assignee = User::query()->findOrFail($data['assignee_id']);
        $deals->reassign($data['ids'], $assignee, $actor, $request);

        return back()->with('status', __('ui.opportunities.moved'));
    }

    private function actor(Request $request, AccessGate $access): User
    {
        $actor = $access->effective($request);
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
