<?php

namespace App\Http\Controllers;

use App\Models\Interaction;
use App\Models\Job;
use App\Models\TaskRule;
use App\Models\User;
use App\Models\WorkTask;
use App\Services\Jobs\FieldWork;
use App\Services\Tasks\TaskBoard;
use App\Support\Access\AccessGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class TaskController extends Controller
{
    public function index(Request $request, TaskBoard $tasks, AccessGate $access, FieldWork $jobs): Response
    {
        $actor = $this->actor($request, $access);
        Gate::authorize('viewAny', WorkTask::class);
        $scope = $tasks->appliedScope($actor, (string) $request->string('scope', 'mine'));
        $view = (string) $request->string('view', 'list');
        $date = Carbon::parse((string) ($request->input('date') ?: now()->toDateString()), (string) config('app.timezone'))->startOfDay();
        [$start, $end] = $this->range($view, $date);

        $query = $tasks->visible($actor, $scope)->with('assignee:id,name');

        if ($request->string('status')->toString() !== '') {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->string('priority')->toString() !== '') {
            $query->where('priority', $request->string('priority')->toString());
        }

        if ($request->boolean('overdue')) {
            $query->where('status', 'open')->where('due_at', '<', now());
        }

        return Inertia::render('Tasks/Index', [
            'tasks' => $query->latest('due_at')->limit(200)->get()->map(fn (WorkTask $task) => $tasks->present($task))->values(),
            'events' => $tasks->calendar($actor, $start, $end, $scope),
            'holidays' => $tasks->holidays($start, $end),
            'filters' => [
                'scope' => $scope,
                'view' => $view,
                'date' => $date->toDateString(),
                'status' => $request->string('status')->toString(),
                'priority' => $request->string('priority')->toString(),
                'overdue' => $request->boolean('overdue'),
            ],
            'people' => $access->allows($actor, 'tasks.assign')
                ? User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                : [],
            'can_create' => $access->allows($actor, 'tasks.create'),
            'can_assign' => $access->allows($actor, 'tasks.assign'),
            'can_see_all' => $access->scopeFor($actor, 'tasks.view') === 'all',
            'can_schedule' => $access->allows($actor, 'jobs.schedule'),
            'rules' => $access->allows($actor, 'tasks.create')
                ? TaskRule::query()->where(fn ($rules) => $rules->where('owner_id', $actor->id)->orWhere('assignee_id', $actor->id))->latest()->limit(20)->get(['id', 'title', 'frequency', 'time_of_day', 'next_run_at'])
                : [],
        ]);
    }

    public function store(Request $request, TaskBoard $tasks, AccessGate $access): RedirectResponse
    {
        Gate::authorize('create', WorkTask::class);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'priority' => ['nullable', 'in:low,normal,high,urgent'],
            'kind' => ['nullable', 'in:task,callback,follow_up_call'],
            'due_at' => ['nullable', 'date'],
            'remind' => ['nullable', 'boolean'],
            'assignee_id' => ['nullable', 'integer', 'exists:users,id'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'ticket_id' => ['nullable', 'integer', 'exists:tickets,id'],
            'job_id' => ['nullable', 'integer', 'exists:field_jobs,id'],
            'lead_id' => ['nullable', 'integer', 'exists:leads,id'],
            'opportunity_id' => ['nullable', 'integer', 'exists:opportunities,id'],
        ]);
        $tasks->create($data, $this->actor($request, $access), $request);

        return back()->with('status', __('ui.tasks.saved'));
    }

    public function storeRule(Request $request, TaskBoard $tasks, AccessGate $access): RedirectResponse
    {
        Gate::authorize('create', WorkTask::class);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'frequency' => ['required', 'in:daily,weekly,monthly'],
            'time_of_day' => ['required', 'regex:/^\d{2}:\d{2}(:\d{2})?$/'],
            'weekday' => ['nullable', 'integer', 'between:0,6'],
            'day_of_month' => ['nullable', 'integer', 'between:1,28'],
            'priority' => ['nullable', 'in:low,normal,high,urgent'],
            'assignee_id' => ['nullable', 'integer', 'exists:users,id'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
        ]);
        $tasks->rule($data, $this->actor($request, $access), $request);

        return back()->with('status', __('ui.tasks.rule_saved'));
    }

    public function reschedule(Request $request, WorkTask $task, TaskBoard $tasks): RedirectResponse
    {
        Gate::authorize('update', $task);
        $data = $request->validate(['due_at' => ['required', 'date']]);
        $tasks->reschedule($task, $data['due_at'], $request->user(), $request);

        return back()->with('status', __('ui.tasks.moved'));
    }

    public function complete(Request $request, WorkTask $task, TaskBoard $tasks): RedirectResponse
    {
        Gate::authorize('update', $task);
        $tasks->complete($task, $request->user(), $request);

        return back()->with('status', __('ui.tasks.done'));
    }

    public function moveEvent(Request $request, TaskBoard $tasks, AccessGate $access, FieldWork $jobs): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        $data = $request->validate([
            'id' => ['required', 'string', 'max:40'],
            'day' => ['required', 'date_format:Y-m-d'],
        ]);
        $id = $data['id'];

        if (str_starts_with($id, 'job-')) {
            abort_unless($access->allows($actor, 'jobs.schedule'), 403);
            $job = Job::query()->findOrFail((int) substr($id, 4));
            abort_unless($jobs->query($actor)->whereKey($job->id)->exists(), 403);
            $tasks->moveJob($job, $data['day'], $actor, $request);
        } elseif (str_starts_with($id, 'call-')) {
            $call = Interaction::query()->findOrFail((int) substr($id, 5));
            abort_unless($tasks->callbacks($actor)->whereKey($call->id)->exists(), 403);
            $tasks->moveCallback($call, $data['day'], $actor, $request);
        } else {
            $task = WorkTask::query()->findOrFail((int) $id);
            Gate::authorize('update', $task);
            $time = $task->due_at?->timezone((string) config('app.timezone'))->format('H:i') ?? '09:00';
            $tasks->reschedule($task, $data['day'].' '.$time, $actor, $request);
        }

        return back()->with('status', __('ui.tasks.moved'));
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function range(string $view, Carbon $date): array
    {
        if ($view === 'day') {
            return [$date->copy(), $date->copy()->endOfDay()];
        }

        if ($view === 'month') {
            return [$date->copy()->startOfMonth()->startOfWeek(Carbon::SUNDAY), $date->copy()->endOfMonth()->endOfWeek(Carbon::SATURDAY)];
        }

        return [$date->copy()->startOfWeek(Carbon::SUNDAY), $date->copy()->endOfWeek(Carbon::SATURDAY)];
    }

    private function actor(Request $request, AccessGate $access): User
    {
        $actor = $access->effective($request);
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
