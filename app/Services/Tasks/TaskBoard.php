<?php

namespace App\Services\Tasks;

use App\Models\Approval;
use App\Models\Holiday;
use App\Models\Interaction;
use App\Models\Job;
use App\Models\TaskRule;
use App\Models\User;
use App\Models\WorkTask;
use App\Notifications\WorkspaceNotification;
use App\Services\Audit\AuditLogger;
use App\Services\Jobs\FieldWork;
use App\Support\Access\AccessGate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

class TaskBoard
{
    public function __construct(
        private AccessGate $access,
        private AuditLogger $audit,
        private FieldWork $jobs,
    ) {}

    public function query(User $viewer): Builder
    {
        $query = WorkTask::query();
        $scope = $this->access->scopeFor($viewer, 'tasks.view');

        if ($scope === null) {
            return $query->whereRaw('0 = 1');
        }

        if ($scope === 'all') {
            return $query;
        }

        return $this->access->restrict($query, $viewer, 'tasks.view', 'assignee_id', 'team_id');
    }

    public function appliedScope(User $viewer, string $scope): string
    {
        $accessScope = $this->access->scopeFor($viewer, 'tasks.view');

        if ($accessScope === 'own' || $scope === 'mine') {
            return 'mine';
        }

        if ($scope === 'all' && $accessScope === 'all') {
            return 'all';
        }

        return 'team';
    }

    public function visible(User $viewer, string $scope): Builder
    {
        $query = $this->query($viewer);
        $applied = $this->appliedScope($viewer, $scope);

        if ($applied === 'mine') {
            return $query->where('assignee_id', $viewer->id);
        }

        if ($applied === 'team') {
            return $this->withinTeam($query, $viewer);
        }

        return $query;
    }

    public function withinTeam(Builder $query, User $viewer): Builder
    {
        $teamIds = $viewer->teams()->pluck('teams.id');

        if ($teamIds->isEmpty()) {
            return $query->where('assignee_id', $viewer->id);
        }

        $memberIds = User::query()
            ->whereHas('teams', fn (Builder $teams) => $teams->whereIn('teams.id', $teamIds))
            ->pluck('id');

        return $query->where(function (Builder $inner) use ($teamIds, $memberIds): void {
            $inner->whereIn('assignee_id', $memberIds)->orWhereIn('team_id', $teamIds);
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(array $input, User $actor, ?Request $request): WorkTask
    {
        $due = $this->at($input['due_at'] ?? null);
        $task = WorkTask::query()->create([
            'kind' => $input['kind'] ?? 'task',
            'title' => $input['title'],
            'notes' => $input['notes'] ?? null,
            'priority' => $input['priority'] ?? 'normal',
            'status' => 'open',
            'due_at' => $due,
            'remind_at' => ! empty($input['remind']) && $due ? $due->copy()->subMinutes(30) : null,
            'customer_id' => $input['customer_id'] ?? null,
            'ticket_id' => $input['ticket_id'] ?? null,
            'job_id' => $input['job_id'] ?? null,
            'lead_id' => $input['lead_id'] ?? null,
            'opportunity_id' => $input['opportunity_id'] ?? null,
            'assignee_id' => $this->access->allows($actor, 'tasks.assign')
                ? ($input['assignee_id'] ?? $actor->id)
                : $actor->id,
            'team_id' => $actor->teams()->value('id'),
            'owner_id' => $actor->id,
        ]);
        $this->audit->record('task.created', $actor, [
            'task_id' => $task->id,
            'customer_id' => $task->customer_id,
            'ticket_id' => $task->ticket_id,
            'job_id' => $task->job_id,
            'lead_id' => $task->lead_id,
        ], $request);

        return $task;
    }

    public function reschedule(WorkTask $task, string $dueAt, User $actor, ?Request $request): void
    {
        $from = $task->due_at?->toIso8601String();
        $task->due_at = $this->at($dueAt);
        $task->overdue_notified = false;
        $task->save();
        $this->audit->record('task.rescheduled', $actor, [
            'task_id' => $task->id,
            'from' => $from,
            'to' => $task->due_at?->toIso8601String(),
        ], $request);
    }

    public function complete(WorkTask $task, User $actor, ?Request $request): void
    {
        $task->status = 'done';
        $task->save();
        $this->audit->record('task.completed', $actor, ['task_id' => $task->id, 'customer_id' => $task->customer_id], $request);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function rule(array $input, User $actor, ?Request $request): TaskRule
    {
        $time = substr((string) $input['time_of_day'], 0, 5);
        $next = $this->firstRun($input['frequency'], $time, $input['weekday'] ?? null, $input['day_of_month'] ?? null);
        $rule = TaskRule::query()->create([
            'title' => $input['title'],
            'notes' => $input['notes'] ?? null,
            'priority' => $input['priority'] ?? 'normal',
            'frequency' => $input['frequency'],
            'weekday' => $input['weekday'] ?? null,
            'day_of_month' => $input['day_of_month'] ?? null,
            'time_of_day' => $time,
            'next_run_at' => $next,
            'active' => true,
            'assignee_id' => $input['assignee_id'] ?? $actor->id,
            'team_id' => $actor->teams()->value('id'),
            'customer_id' => $input['customer_id'] ?? null,
            'owner_id' => $actor->id,
        ]);
        $this->audit->record('task_rule.created', $actor, ['rule_id' => $rule->id, 'frequency' => $rule->frequency], $request);

        return $rule;
    }

    public function generate(): int
    {
        $made = 0;

        TaskRule::query()->where('active', true)->where('next_run_at', '<=', now())->orderBy('id')->each(function (TaskRule $rule) use (&$made): void {
            $due = $rule->next_run_at?->copy() ?? now();
            $exists = WorkTask::query()->where('rule_id', $rule->id)->whereDate('occurrence_on', $due->toDateString())->exists();

            if (! $exists) {
                $task = WorkTask::query()->create([
                    'kind' => 'task',
                    'title' => $rule->title,
                    'notes' => $rule->notes,
                    'priority' => $rule->priority,
                    'status' => 'open',
                    'due_at' => $due,
                    'remind_at' => $due->copy()->subMinutes(30),
                    'occurrence_on' => $due->toDateString(),
                    'rule_id' => $rule->id,
                    'assignee_id' => $rule->assignee_id,
                    'team_id' => $rule->team_id,
                    'customer_id' => $rule->customer_id,
                    'owner_id' => $rule->owner_id,
                ]);
                $this->audit->record('task.created', null, [
                    'task_id' => $task->id,
                    'rule_id' => $rule->id,
                    'source' => 'recurring',
                ]);
                $made++;
            }

            $next = $due->copy();

            do {
                $next = match ($rule->frequency) {
                    'weekly' => $next->addWeek(),
                    'monthly' => $next->addMonthNoOverflow(),
                    default => $next->addDay(),
                };
            } while ($next->lessThanOrEqualTo(now()));

            $rule->next_run_at = $next;
            $rule->save();
        });

        return $made;
    }

    public function remind(): int
    {
        $sent = 0;

        WorkTask::query()
            ->with('assignee')
            ->where('status', 'open')
            ->where('reminded', false)
            ->whereNotNull('remind_at')
            ->where('remind_at', '<=', now())
            ->each(function (WorkTask $task) use (&$sent): void {
                $this->notify($task, __('ui.notifications.task_reminder_title', ['title' => $task->title]), __('ui.notifications.task_reminder_body'));
                $task->reminded = true;
                $task->save();
                $sent++;
            });

        WorkTask::query()
            ->with('assignee')
            ->where('status', 'open')
            ->where('overdue_notified', false)
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->each(function (WorkTask $task) use (&$sent): void {
                $this->notify($task, __('ui.notifications.task_overdue_title', ['title' => $task->title]), __('ui.notifications.task_overdue_body'));
                $task->overdue_notified = true;
                $task->save();
                $sent++;
            });

        return $sent;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function calendar(User $viewer, Carbon $start, Carbon $end, string $scope = 'mine'): array
    {
        $events = [];

        $this->visible($viewer, $scope)
            ->with('assignee:id,name')
            ->whereBetween('due_at', [$start, $end])
            ->orderBy('due_at')
            ->get()
            ->each(function (WorkTask $task) use (&$events): void {
                $events[] = $this->present($task) + [
                    'event' => $task->kind === 'callback' ? 'callback' : 'task',
                    'draggable' => true,
                ];
            });

        $this->jobs->query($viewer)
            ->with('customer:id,name_en,name_ar')
            ->whereBetween('window_start', [$start, $end])
            ->orderBy('window_start')
            ->get()
            ->each(function (Job $job) use (&$events, $viewer): void {
                $events[] = [
                    'id' => 'job-'.$job->id,
                    'event' => 'job',
                    'title' => $job->number.' · '.($job->customer?->name_en ?? ''),
                    'due_at' => $job->window_start?->timezone((string) config('app.timezone'))->format('Y-m-d H:i'),
                    'status' => $job->status,
                    'priority' => 'normal',
                    'overdue' => false,
                    'draggable' => $this->access->allows($viewer, 'jobs.schedule'),
                ];
            });

        $this->callbacks($viewer)
            ->whereBetween('callback_at', [$start, $end])
            ->orderBy('callback_at')
            ->get()
            ->each(function (Interaction $call) use (&$events): void {
                $events[] = [
                    'id' => 'call-'.$call->id,
                    'event' => 'callback',
                    'title' => __('ui.tasks.callback'),
                    'due_at' => $call->callback_at?->timezone((string) config('app.timezone'))->format('Y-m-d H:i'),
                    'status' => 'open',
                    'priority' => 'normal',
                    'overdue' => $call->callback_at?->isPast() ?? false,
                    'draggable' => true,
                ];
            });

        Approval::query()
            ->where('status', 'pending')
            ->whereBetween('due_at', [$start, $end])
            ->orderBy('due_at')
            ->get()
            ->each(function (Approval $approval) use (&$events): void {
                $events[] = [
                    'id' => 'approval-'.$approval->id,
                    'event' => 'approval',
                    'title' => __('ui.approvals.due', ['summary' => $approval->summary]),
                    'due_at' => $approval->due_at?->timezone((string) config('app.timezone'))->format('Y-m-d H:i'),
                    'status' => 'open',
                    'priority' => 'high',
                    'overdue' => $approval->due_at?->isPast() ?? false,
                    'draggable' => false,
                ];
            });

        return $events;
    }

    public function callbacks(User $viewer): Builder
    {
        $query = Interaction::query()->whereNotNull('callback_at');
        $scope = $this->access->scopeFor($viewer, 'calls.view') ?? $this->access->scopeFor($viewer, 'calls.log');

        if ($scope === null) {
            return $query->whereRaw('0 = 1');
        }

        if ($scope === 'all') {
            return $query;
        }

        return $query->where('user_id', $viewer->id);
    }

    /**
     * @return list<array{date: string, name_en: string, name_ar: string}>
     */
    public function holidays(Carbon $start, Carbon $end): array
    {
        return Holiday::query()
            ->whereDate('observed_on', '>=', $start->toDateString())
            ->whereDate('observed_on', '<=', $end->toDateString())
            ->orderBy('observed_on')
            ->get()
            ->map(fn (Holiday $holiday) => [
                'date' => $holiday->observed_on?->toDateString() ?? '',
                'name_en' => $holiday->name_en,
                'name_ar' => $holiday->name_ar,
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function present(WorkTask $task): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'kind' => $task->kind,
            'status' => $task->status,
            'priority' => $task->priority,
            'due_at' => $task->due_at?->timezone((string) config('app.timezone'))->format('Y-m-d H:i'),
            'overdue' => $task->isOverdue(),
            'assignee' => $task->assignee?->name,
            'customer_id' => $task->customer_id,
            'ticket_id' => $task->ticket_id,
            'job_id' => $task->job_id,
            'lead_id' => $task->lead_id,
            'notes' => $task->notes,
        ];
    }

    public function moveJob(Job $job, string $day, User $actor, Request $request): void
    {
        $start = $job->window_start?->timezone((string) config('app.timezone')) ?? now();
        $end = $job->window_end?->timezone((string) config('app.timezone')) ?? $start->copy()->addHours(2);
        $newStart = Carbon::parse($day.' '.$start->format('H:i'), (string) config('app.timezone'));
        $newEnd = Carbon::parse($day.' '.$end->format('H:i'), (string) config('app.timezone'));
        $this->jobs->schedule($job, [
            'window_start' => $newStart->format('Y-m-d H:i'),
            'window_end' => $newEnd->format('Y-m-d H:i'),
            'status' => 'rescheduled',
        ], $actor, $request);
    }

    public function moveCallback(Interaction $call, string $day, User $actor, Request $request): void
    {
        $time = $call->callback_at?->timezone((string) config('app.timezone'))->format('H:i') ?? '09:00';
        $call->callback_at = Carbon::parse($day.' '.$time, (string) config('app.timezone'));
        $call->save();
        $this->audit->record('call.callback_moved', $actor, [
            'interaction_id' => $call->id,
            'callback_at' => $call->callback_at->toIso8601String(),
        ], $request);
    }

    private function notify(WorkTask $task, string $title, string $body): void
    {
        $person = $task->assignee;

        if ($person instanceof User) {
            Notification::send($person, new WorkspaceNotification($title, $body));
        }
    }

    private function at(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return Carbon::parse($value, (string) config('app.timezone'));
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function firstRun(string $frequency, string $time, mixed $weekday, mixed $day): Carbon
    {
        $run = Carbon::parse(now()->toDateString().' '.substr($time, 0, 5), (string) config('app.timezone'));

        if ($frequency === 'weekly' && is_numeric($weekday)) {
            $target = ((int) $weekday) % 7;

            while ($run->dayOfWeek !== $target) {
                $run->addDay();
            }
        }

        if ($frequency === 'monthly' && is_numeric($day)) {
            $wanted = min(max((int) $day, 1), 28);
            $run = $run->day(min($wanted, $run->daysInMonth));

            if ($run->lessThanOrEqualTo(now())) {
                $run = $run->addMonthNoOverflow()->day(min($wanted, $run->daysInMonth));
            }

            return $run;
        }

        if ($run->lessThanOrEqualTo(now())) {
            $run = $frequency === 'weekly' ? $run->addWeek() : $run->addDay();
        }

        return $run;
    }
}
