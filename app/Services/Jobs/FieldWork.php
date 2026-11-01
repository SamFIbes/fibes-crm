<?php

namespace App\Services\Jobs;

use App\Models\ChecklistTemplate;
use App\Models\CsatResponse;
use App\Models\Customer;
use App\Models\Job;
use App\Models\JobCheck;
use App\Models\NetworkNode;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use App\Models\UserSkill;
use App\Models\WorkTask;
use App\Notifications\WorkspaceNotification;
use App\Services\Audit\AuditLogger;
use App\Support\Access\AccessGate;
use App\Support\Access\FieldPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FieldWork
{
    public const TYPES = ['installation', 'repair', 'relocation', 'survey', 'maintenance', 'removal', 'equipment_swap'];

    public const STATUSES = ['scheduled', 'en_route', 'on_site', 'done', 'failed', 'rescheduled'];

    public const SKILLS = ['fiber', 'wireless', 'indoor', 'tower'];

    public const FAILURES = ['customer_absent', 'no_access', 'no_light', 'equipment', 'weather', 'other'];

    public const NODE_KINDS = ['exchange', 'olt', 'pon', 'splitter', 'tower', 'sector', 'ptp'];

    public function __construct(
        private AccessGate $access,
        private AuditLogger $audit,
        private FieldPolicy $fields,
    ) {}

    public function seed(): void
    {
        $rows = [
            ['job', 'repair', null, 'Check power at the ONT', 'افحص الكهرباء عند جهاز الألياف', true],
            ['job', 'repair', null, 'Check the lights', 'افحص الأضواء', true],
            ['job', 'repair', null, 'Photo of the drop cable', 'صورة لكبل التوصيل', false],
            ['job', 'installation', null, 'Confirm the address', 'تأكد من العنوان', true],
            ['job', 'installation', null, 'Photo after install', 'صورة بعد التركيب', true],
            ['job', 'survey', null, 'Line of sight', 'خط النظر', true],
            ['job', 'relocation', null, 'New address confirmed', 'العنوان الجديد مؤكد', true],
            ['job', 'maintenance', null, 'Reading noted', 'القراءة مسجّلة', true],
        ];

        foreach ($rows as $index => [$kind, $type, $category, $en, $ar, $required]) {
            ChecklistTemplate::query()->updateOrCreate(
                ['kind' => $kind, 'job_type' => $type, 'label_en' => $en],
                ['ticket_category_id' => $category, 'label_ar' => $ar, 'required' => $required, 'sort' => $index],
            );
        }

        $category = TicketCategory::query()->where('key', 'no_internet')->value('id');

        if ($category) {
            ChecklistTemplate::query()->updateOrCreate(
                ['kind' => 'ticket', 'ticket_category_id' => $category, 'label_en' => 'Power at the premises'],
                ['job_type' => null, 'label_ar' => 'الكهرباء في الموقع', 'required' => false, 'sort' => 0],
            );
        }

        if (! NetworkNode::query()->exists()) {
            NetworkNode::query()->create(['kind' => 'exchange', 'name' => 'Beirut central office', 'notes' => 'Starter node.']);
        }
    }

    public function query(User $viewer): Builder
    {
        $query = Job::query();

        if ($this->access->allows($viewer, 'jobs.schedule') || $this->access->scopeFor($viewer, 'jobs.view') === 'all') {
            return $query;
        }

        $scope = $this->access->scopeFor($viewer, 'jobs.view') ?? $this->access->scopeFor($viewer, 'jobs.complete');

        if ($scope === 'own') {
            return $query->where('technician_id', $viewer->id);
        }

        if ($scope === null) {
            return $query->whereRaw('0 = 1');
        }

        return $this->access->restrict($query, $viewer, 'jobs.view', 'technician_id', 'team_id');
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(array $input, User $actor, ?Request $request): Job
    {
        return DB::transaction(function () use ($input, $actor, $request) {
            $job = Job::query()->create([
                'number' => $this->nextNumber(),
                'type' => $input['type'],
                'status' => ($input['status'] ?? null) === 'requested' ? 'requested' : 'scheduled',
                'customer_id' => $input['customer_id'],
                'customer_service_id' => $input['customer_service_id'] ?? null,
                'ticket_id' => $input['ticket_id'] ?? null,
                'team_id' => $input['team_id'] ?? null,
                'technician_id' => $input['technician_id'] ?? null,
                'required_skill' => $input['required_skill'] ?? null,
                'window_start' => $this->at($input['window_start'] ?? null),
                'window_end' => $this->at($input['window_end'] ?? null),
                'notes' => $input['notes'] ?? null,
                'owner_id' => $actor->id,
                'opportunity_id' => $input['opportunity_id'] ?? null,
                'order_id' => $input['order_id'] ?? null,
            ]);
            $this->copyJobChecks($job);
            $this->audit->record('job.created', $actor, [
                'job_id' => $job->id,
                'customer_id' => $job->customer_id,
                'ticket_id' => $job->ticket_id,
                'type' => $job->type,
                'number' => $job->number,
            ], $request);
            $this->notify($job, __('ui.notifications.job_opened_title', ['number' => $job->number]), __('ui.notifications.job_opened_body'));

            return $job;
        });
    }

    public function ensureRepair(Ticket $ticket, ?User $actor, ?Request $request): ?Job
    {
        $existing = Job::query()
            ->where('ticket_id', $ticket->id)
            ->where('type', 'repair')
            ->whereNotIn('status', ['done', 'failed'])
            ->first();

        if ($existing instanceof Job) {
            return $existing;
        }

        $owner = $actor ?? $ticket->owner;

        if (! $owner instanceof User) {
            return null;
        }

        return $this->create([
            'type' => 'repair',
            'customer_id' => $ticket->customer_id,
            'customer_service_id' => $ticket->customer_service_id,
            'ticket_id' => $ticket->id,
            'notes' => $ticket->handover_note,
        ], $owner, $request);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    public function schedule(Job $job, array $input, User $actor, Request $request): array
    {
        $start = array_key_exists('window_start', $input) ? $this->at($input['window_start']) : $job->window_start;
        $end = array_key_exists('window_end', $input) ? $this->at($input['window_end']) : $job->window_end;
        $technician = array_key_exists('technician_id', $input) ? ($input['technician_id'] ?: null) : $job->technician_id;
        $warnings = [];

        if ($technician && $start instanceof Carbon && $end instanceof Carbon) {
            $warnings = $this->conflicts($job, (int) $technician, $start, $end);
        }

        $job->fill([
            'technician_id' => $technician,
            'team_id' => $input['team_id'] ?? $job->team_id,
            'window_start' => $start,
            'window_end' => $end,
            'required_skill' => $input['required_skill'] ?? $job->required_skill,
            'status' => ($input['status'] ?? null) === 'rescheduled' ? 'rescheduled' : ($job->status === 'rescheduled' ? 'scheduled' : $job->status),
        ])->save();

        $this->audit->record('job.scheduled', $actor, [
            'job_id' => $job->id,
            'customer_id' => $job->customer_id,
            'technician_id' => $job->technician_id,
            'warnings' => $warnings,
        ], $request);

        if ($job->technician_id) {
            $this->notify($job->fresh() ?? $job, __('ui.notifications.job_scheduled_title', ['number' => $job->number]), __('ui.notifications.job_scheduled_body'));
        }

        return $warnings;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function changeStatus(Job $job, string $status, array $input, User $actor, Request $request, ?string $idempotency = null): void
    {
        if ($idempotency) {
            $seen = DB::table('job_sync_keys')->where('idempotency_key', $idempotency)->exists();

            if ($seen) {
                return;
            }
        }

        if ($status === 'done') {
            $open = $job->checks()->where('required', true)->where('done', false)->exists();

            if ($open || $job->signature_attachment_id === null || blank($job->signed_by)) {
                throw ValidationException::withMessages(['status' => __('ui.jobs.finish_blocked')]);
            }
        }

        if ($status === 'failed' && blank($input['failure_reason'] ?? null)) {
            throw ValidationException::withMessages(['failure_reason' => __('ui.jobs.reason_required')]);
        }

        $from = $job->status;
        $job->status = $status;
        $job->failure_reason = $input['failure_reason'] ?? $job->failure_reason;
        $job->failure_note = $input['failure_note'] ?? $job->failure_note;
        $job->save();

        if ($idempotency) {
            DB::table('job_sync_keys')->insert([
                'idempotency_key' => $idempotency,
                'job_id' => $job->id,
                'action' => $status,
                'created_at' => now(),
            ]);
        }

        $this->audit->record('job.status', $actor, [
            'job_id' => $job->id,
            'customer_id' => $job->customer_id,
            'from' => $from,
            'to' => $status,
        ], $request);
    }

    public function toggleCheck(JobCheck $check, bool $done, User $actor, Request $request): void
    {
        $check->done = $done;
        $check->save();
        $this->audit->record('job.check', $actor, [
            'job_id' => $check->job_id,
            'check_id' => $check->id,
            'done' => $done,
        ], $request);
    }

    public function copyTicketChecks(Ticket $ticket): void
    {
        if ($ticket->checks()->exists()) {
            return;
        }

        ChecklistTemplate::query()
            ->where('kind', 'ticket')
            ->where('ticket_category_id', $ticket->category_id)
            ->orderBy('sort')
            ->get()
            ->each(function (ChecklistTemplate $template) use ($ticket): void {
                $ticket->checks()->create([
                    'label_en' => $template->label_en,
                    'label_ar' => $template->label_ar,
                    'required' => $template->required,
                    'sort' => $template->sort,
                ]);
            });
    }

    public function addTicketTemplate(int $categoryId, string $en, string $ar): void
    {
        ChecklistTemplate::query()->create([
            'kind' => 'ticket',
            'ticket_category_id' => $categoryId,
            'label_en' => $en,
            'label_ar' => $ar,
            'required' => false,
            'sort' => (int) ChecklistTemplate::query()->where('ticket_category_id', $categoryId)->max('sort') + 1,
        ]);
    }

    public function afterTicketResolved(Ticket $ticket, User $actor, ?Request $request): void
    {
        CsatResponse::query()->firstOrCreate(['ticket_id' => $ticket->id], [
            'token' => Str::random(40),
        ]);

        $exists = WorkTask::query()
            ->where('ticket_id', $ticket->id)
            ->where('kind', 'follow_up_call')
            ->where('status', 'open')
            ->exists();

        if ($exists) {
            return;
        }

        $task = WorkTask::query()->create([
            'kind' => 'follow_up_call',
            'ticket_id' => $ticket->id,
            'customer_id' => $ticket->customer_id,
            'assignee_id' => $ticket->owner_id,
            'due_at' => now()->addDay(),
            'status' => 'open',
            'title' => __('ui.jobs.follow_up_title', ['number' => $ticket->number]),
        ]);
        $this->audit->record('task.follow_up', $actor, [
            'task_id' => $task->id,
            'ticket_id' => $ticket->id,
            'customer_id' => $ticket->customer_id,
        ], $request);

        $owner = $ticket->owner;

        if ($owner instanceof User) {
            Notification::send($owner, new WorkspaceNotification(
                __('ui.notifications.follow_up_title', ['number' => $ticket->number]),
                __('ui.notifications.follow_up_body'),
            ));
        }
    }

    public function recordCsat(CsatResponse $csat, int $score, ?string $comment, string $source, ?User $actor, ?Request $request): void
    {
        $csat->fill([
            'score' => $score,
            'comment' => $comment,
            'source' => $source,
            'recorded_by' => $actor?->id,
        ])->save();
        $this->audit->record('ticket.csat', $actor, [
            'ticket_id' => $csat->ticket_id,
            'score' => $score,
            'source' => $source,
        ], $request);
    }

    /**
     * @return list<string>
     */
    public function conflicts(Job $job, int $technicianId, Carbon $start, Carbon $end): array
    {
        $warnings = [];
        $overlap = Job::query()
            ->where('technician_id', $technicianId)
            ->where('id', '!=', $job->id)
            ->whereNotIn('status', ['done', 'failed'])
            ->whereNotNull('window_start')
            ->where('window_start', '<', $end)
            ->where('window_end', '>', $start)
            ->exists();

        if ($overlap) {
            $warnings[] = 'overlap';
        }

        if (filled($job->required_skill) && ! UserSkill::query()->where('user_id', $technicianId)->where('skill', $job->required_skill)->exists()) {
            $warnings[] = 'skill';
        }

        return $warnings;
    }

    /**
     * @return array<string, mixed>
     */
    public function present(Job $job, User $viewer, bool $detail = false): array
    {
        $job->loadMissing(['customer.area', 'customer.phones', 'technician:id,name', 'service:id,service_type', 'checks']);
        $customer = $job->customer;
        $pii = $customer ? $this->fields->present($viewer, [
            'phone' => $customer->phones->first()?->phone_e164,
            'address' => $customer->area?->name_en,
        ], [
            'assigned_job' => (int) $job->technician_id === (int) $viewer->id,
            'job_starts_at' => $job->window_start,
            'job_ends_at' => $job->window_end,
        ]) : [];

        $row = [
            'id' => $job->id,
            'number' => $job->number,
            'type' => $job->type,
            'status' => $job->status,
            'required_skill' => $job->required_skill,
            'window_start' => $job->window_start?->timezone(config('app.timezone'))->format('Y-m-d H:i'),
            'window_end' => $job->window_end?->timezone(config('app.timezone'))->format('Y-m-d H:i'),
            'technician' => $job->technician?->name,
            'technician_id' => $job->technician_id,
            'customer' => [
                'id' => $job->customer_id,
                'name_en' => $customer?->name_en,
                'name_ar' => $customer?->name_ar,
                'account_no' => $customer?->account_no,
                'phone' => $pii['phone'] ?? null,
                'area_en' => $customer?->area?->name_en,
                'area_ar' => $customer?->area?->name_ar,
            ],
            'service_type' => $job->service?->service_type,
            'latitude' => $customer?->latitude,
            'longitude' => $customer?->longitude,
            'maps' => $this->mapsLink($customer),
        ];

        if ($detail) {
            $row['notes'] = $job->notes;
            $row['materials'] = $job->materials;
            $row['failure_reason'] = $job->failure_reason;
            $row['signed_by'] = $job->signed_by;
            $row['checks'] = $job->checks->sortBy('sort')->values()->map(fn (JobCheck $check) => [
                'id' => $check->id,
                'label_en' => $check->label_en,
                'label_ar' => $check->label_ar,
                'required' => $check->required,
                'done' => $check->done,
            ])->all();
            $row['files'] = $job->files()->latest()->get()->map(fn ($file) => [
                'id' => $file->id,
                'name' => $file->original_name,
            ])->all();
        }

        return $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function repairFor(Ticket $ticket): ?array
    {
        $job = Job::query()->where('ticket_id', $ticket->id)->latest('id')->first();

        if (! $job instanceof Job) {
            return null;
        }

        return ['id' => $job->id, 'number' => $job->number, 'status' => $job->status, 'type' => $job->type];
    }

    private function copyJobChecks(Job $job): void
    {
        ChecklistTemplate::query()
            ->where('kind', 'job')
            ->where('job_type', $job->type)
            ->orderBy('sort')
            ->get()
            ->each(function (ChecklistTemplate $template) use ($job): void {
                $job->checks()->create([
                    'label_en' => $template->label_en,
                    'label_ar' => $template->label_ar,
                    'required' => $template->required,
                    'sort' => $template->sort,
                ]);
            });
    }

    private function notify(Job $job, string $title, string $body): void
    {
        $person = $job->technician;

        if ($person instanceof User) {
            Notification::send($person, new WorkspaceNotification($title, $body));
        }
    }

    private function mapsLink(?Customer $customer): ?string
    {
        if (! $customer) {
            return null;
        }

        if ($customer->latitude !== null && $customer->longitude !== null) {
            return 'https://www.google.com/maps/dir/?api=1&destination='.$customer->latitude.','.$customer->longitude;
        }

        $area = $customer->area?->name_en;

        return $area ? 'https://www.google.com/maps/search/?api=1&query='.urlencode($area.' Lebanon') : null;
    }

    private function at(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return Carbon::parse($value, (string) config('app.timezone'));
    }

    private function nextNumber(): string
    {
        $last = Job::query()->lockForUpdate()->orderByDesc('id')->value('number');
        $next = 1;

        if (is_string($last) && preg_match('/(\d+)$/', $last, $match)) {
            $next = ((int) $match[1]) + 1;
        }

        return 'JB-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }
}
