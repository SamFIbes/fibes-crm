<?php

namespace App\Services\Qa;

use App\Models\AgentStatusLog;
use App\Models\CsatResponse;
use App\Models\Interaction;
use App\Models\Lead;
use App\Models\QaEvaluation;
use App\Models\QaEvent;
use App\Models\QaForm;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Settings\CommercialSettings;
use App\Support\Access\AccessGate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QaDesk
{
    public function __construct(
        private CommercialSettings $commercial,
        private EvaluationMachine $machine,
        private AuditLogger $audit,
        private AccessGate $access,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $criteria
     */
    public function reviseForm(QaForm $current, array $criteria, User $actor, Request $request): QaForm
    {
        $current->is_current = false;
        $current->save();
        $next = QaForm::query()->create([
            'key' => $current->key,
            'version' => $current->version + 1,
            'name_en' => $current->name_en,
            'name_ar' => $current->name_ar,
            'criteria' => $criteria,
            'is_current' => true,
            'created_by' => $actor->id,
        ]);
        $this->audit->record('qa.form_revised', $actor, ['form_id' => $next->id, 'version' => $next->version], $request);

        return $next;
    }

    /**
     * @param  array<string, int|float|string>  $scores
     */
    public function score(string $subjectType, int $subjectId, int $agentId, array $scores, ?string $notes, User $actor, Request $request): QaEvaluation
    {
        $this->guardSubject($actor, $subjectType, $subjectId);
        $form = QaForm::query()->where('is_current', true)->where('key', 'call')->firstOrFail();
        $graded = $this->grade($form, $scores);
        $evaluation = QaEvaluation::query()->create([
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'call_id' => $subjectType === 'interaction' ? $subjectId : null,
            'agent_id' => $agentId,
            'evaluator_id' => $actor->id,
            'form_id' => $form->id,
            'form_version' => $form->version,
            'scores' => $scores,
            'total_pct' => $graded['total'],
            'critical_fail' => $graded['critical'],
            'coaching_notes' => $notes,
            'status' => 'submitted',
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
        $this->event($evaluation, null, 'submitted', $actor);
        $this->audit->record('qa.scored', $actor, [
            'evaluation_id' => $evaluation->id,
            'agent_id' => $agentId,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'total' => $graded['total'],
            'critical_fail' => $graded['critical'],
        ], $request);

        return $evaluation;
    }

    public function acknowledge(QaEvaluation $evaluation, User $actor, Request $request): void
    {
        if ((int) $evaluation->agent_id !== (int) $actor->id) {
            throw ValidationException::withMessages(['status' => __('ui.qa.not_yours')]);
        }

        $this->machine->assert((string) $evaluation->status, 'acknowledged');
        $from = (string) $evaluation->status;
        $evaluation->status = 'acknowledged';
        $evaluation->acknowledged_at = now();
        $evaluation->updated_by = $actor->id;
        $evaluation->save();
        $this->event($evaluation, $from, 'acknowledged', $actor);
        $this->audit->record('qa.acknowledged', $actor, ['evaluation_id' => $evaluation->id], $request);
    }

    public function sample(Carbon $week, User $actor): int
    {
        $limit = max(1, (int) $this->commercial->get('qa_weekly_sample'));
        $start = $week->copy()->startOfWeek();
        $end = $start->copy()->endOfWeek();
        $picked = 0;
        $agents = User::query()->where('role', 'cc_agent')->orderBy('id')->pluck('id');

        foreach ($agents as $agentId) {
            $already = DB::table('qa_samples')->where('agent_id', $agentId)->whereDate('week_start', $start->toDateString())->count();
            $need = $limit - $already;

            if ($need < 1) {
                continue;
            }

            $ids = Interaction::query()
                ->where('user_id', $agentId)
                ->whereBetween('created_at', [$start, $end])
                ->whereNotIn('id', DB::table('qa_samples')->where('agent_id', $agentId)->whereDate('week_start', $start->toDateString())->pluck('interaction_id'))
                ->orderBy('id')
                ->limit($need)
                ->pluck('id');

            foreach ($ids as $id) {
                DB::table('qa_samples')->insert([
                    'agent_id' => $agentId,
                    'interaction_id' => $id,
                    'week_start' => $start->toDateString(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $picked++;
            }
        }

        $this->audit->record('qa.sampled', $actor, ['week' => $start->toDateString(), 'picked' => $picked], Request::create('/'));

        return $picked;
    }

    /**
     * @return array<string, int>
     */
    public function statusTime(int $agentId, string $from, string $to): array
    {
        $totals = array_fill_keys(['available', 'on_call', 'break', 'training', 'offline', 'acw'], 0);
        $rows = AgentStatusLog::query()
            ->where('user_id', $agentId)
            ->whereDate('started_at', '>=', $from)
            ->whereDate('started_at', '<=', $to)
            ->get();

        foreach ($rows as $row) {
            $seconds = (int) round($row->started_at->diffInSeconds($row->ended_at ?? now()));
            $status = (string) $row->status;
            $totals[$status] = ($totals[$status] ?? 0) + $seconds;
        }

        return $totals;
    }

    public function setStatus(User $agent, string $status, User $actor, Request $request): void
    {
        if (! in_array($status, ['available', 'on_call', 'break', 'training', 'offline', 'acw'], true)) {
            throw ValidationException::withMessages(['status' => __('ui.qa.illegal')]);
        }

        AgentStatusLog::query()->where('user_id', $agent->id)->whereNull('ended_at')->update(['ended_at' => now()]);
        AgentStatusLog::query()->create([
            'user_id' => $agent->id,
            'status' => $status,
            'started_at' => now(),
        ]);
        $this->audit->record('qa.status', $actor, ['user_id' => $agent->id, 'status' => $status], $request);
    }

    public function visible(User $user): Builder
    {
        $query = QaEvaluation::query()->whereNull('archived_at');

        if ($user->role === 'sales_manager') {
            return $query->whereIn('subject_type', ['interaction', 'retention']);
        }

        if ($this->access->allows($user, 'qa.score') || $this->access->scopeFor($user, 'qa.view') === 'all') {
            return $query;
        }

        if ($this->access->scopeFor($user, 'qa.view') === 'own') {
            return $query->where('agent_id', $user->id);
        }

        return $query->whereRaw('0 = 1');
    }

    /**
     * @param  array<string, int|float|string>  $scores
     * @return array{total: string, critical: bool}
     */
    public function grade(QaForm $form, array $scores): array
    {
        $weight = 0;
        $earned = 0.0;
        $critical = false;

        foreach ($form->criteria ?? [] as $criterion) {
            $key = (string) ($criterion['key'] ?? '');
            $rowWeight = (int) ($criterion['weight'] ?? 0);
            $score = (float) ($scores[$key] ?? 0);
            $weight += $rowWeight;
            $earned += ($score / 100) * $rowWeight;

            if (! empty($criterion['critical']) && $score <= 0) {
                $critical = true;
            }
        }

        if ($critical) {
            return ['total' => bcadd((string) $this->commercial->get('qa_critical_total'), '0', 2), 'critical' => true];
        }

        $total = $weight > 0 ? $this->roundPercent(($earned / $weight) * 100) : '0.00';

        return ['total' => $total, 'critical' => false];
    }

    /**
     * @return array<string, array{value: string|null, hrefs: list<string>}>
     */
    public function kpis(int $agentId, string $from, string $to): array
    {
        $calls = Interaction::query()->where('user_id', $agentId)->whereDate('created_at', '>=', $from)->whereDate('created_at', '<=', $to);
        $callCount = (clone $calls)->count();
        $aht = (clone $calls)->avg('duration_seconds');
        $acw = AgentStatusLog::query()->where('user_id', $agentId)->where('status', 'acw')->whereDate('started_at', '>=', $from)->whereDate('started_at', '<=', $to)->get();
        $acwSeconds = 0;
        foreach ($acw as $row) {
            $acwSeconds += $row->started_at->diffInSeconds($row->ended_at ?? now());
        }
        $tickets = Ticket::query()->where('owner_id', $agentId)->whereDate('created_at', '>=', $from)->whereDate('created_at', '<=', $to);
        $ticketCount = (clone $tickets)->count();
        $l1 = (clone $tickets)->where('status', 'resolved')->whereNull('escalation_reason')->whereNull('escalation_tier_id')->count();
        $resolved = (clone $tickets)->where('status', 'resolved')->count();
        $sla = (clone $tickets)->where('status', 'resolved')->where('resolve_breached', false)->count();
        $callbacks = Interaction::query()->where('user_id', $agentId)->whereNotNull('callback_at')->whereDate('callback_at', '>=', $from)->whereDate('callback_at', '<=', $to);
        $callbackCount = (clone $callbacks)->count();
        $onTime = 0;
        foreach ((clone $callbacks)->get() as $call) {
            $done = Interaction::query()->where('user_id', $agentId)->where('customer_id', $call->customer_id)->where('id', '!=', $call->id)->where('created_at', '<=', $call->callback_at)->exists();
            if ($done) {
                $onTime++;
            }
        }
        $leads = Lead::query()->where('owner_id', $agentId)->whereDate('created_at', '>=', $from)->whereDate('created_at', '<=', $to);
        $leadCount = (clone $leads)->count();
        $qualified = (clone $leads)->where('status', 'qualified')->count();
        $qa = QaEvaluation::query()->where('agent_id', $agentId)->whereDate('created_at', '>=', $from)->whereDate('created_at', '<=', $to)->avg('total_pct');
        $csat = CsatResponse::query()->whereHas('ticket', fn ($query) => $query->where('owner_id', $agentId))->whereNotNull('score')->avg('score');

        return [
            'calls' => ['value' => (string) $callCount, 'hrefs' => ['/calls']],
            'aht' => ['value' => (string) (int) round((float) ($aht ?? 0)), 'hrefs' => ['/calls']],
            'acw' => ['value' => (string) (int) ($acw->count() > 0 ? round($acwSeconds / $acw->count()) : 0), 'hrefs' => ['/qa']],
            'fcr' => ['value' => $this->percent($l1, $ticketCount), 'hrefs' => $this->ticketLinks($agentId, $from, $to)],
            'abandoned' => ['value' => null, 'hrefs' => []],
            'callbacks' => ['value' => $this->percent($onTime, $callbackCount), 'hrefs' => ['/calls']],
            'leads' => ['value' => (string) $leadCount, 'hrefs' => ['/leads']],
            'qualified' => ['value' => (string) $qualified, 'hrefs' => ['/leads']],
            'conversion' => ['value' => $this->percent($qualified, $leadCount), 'hrefs' => ['/leads']],
            'tickets' => ['value' => (string) $ticketCount, 'hrefs' => $this->ticketLinks($agentId, $from, $to)],
            'resolved_l1' => ['value' => (string) $l1, 'hrefs' => $this->ticketLinks($agentId, $from, $to)],
            'sla' => ['value' => $this->percent($sla, $resolved), 'hrefs' => $this->ticketLinks($agentId, $from, $to)],
            'qa' => ['value' => $qa === null ? '0.00' : $this->roundPercent((float) $qa), 'hrefs' => ['/qa']],
            'csat' => ['value' => $csat === null ? '0.00' : $this->roundPercent((float) $csat), 'hrefs' => $this->ticketLinks($agentId, $from, $to)],
        ];
    }

    /**
     * @return array{left: string, right: string, variance: string}|null
     */
    public function calibration(string $subjectType, int $subjectId): ?array
    {
        $rows = QaEvaluation::query()->where('subject_type', $subjectType)->where('subject_id', $subjectId)->orderBy('id')->limit(2)->get();

        if ($rows->count() < 2) {
            return null;
        }

        $left = (string) $rows[0]->total_pct;
        $right = (string) $rows[1]->total_pct;

        return ['left' => $left, 'right' => $right, 'variance' => bcsub($left > $right ? $left : $right, $left > $right ? $right : $left, 2)];
    }

    /**
     * @param  array<string, int|float|string>  $scores
     */
    private function guardSubject(User $actor, string $subjectType, int $subjectId): void
    {
        if (! in_array($subjectType, ['interaction', 'ticket', 'escalation', 'retention'], true)) {
            throw ValidationException::withMessages(['subject' => __('ui.qa.illegal')]);
        }

        if ($actor->role !== 'sales_manager') {
            return;
        }

        if ($subjectType === 'retention') {
            return;
        }

        if ($subjectType === 'interaction') {
            $call = Interaction::query()->find($subjectId);

            if ($call instanceof Interaction && $call->lead_id) {
                return;
            }
        }

        throw ValidationException::withMessages(['subject' => __('ui.qa.sales_only')]);
    }

    private function percent(int $part, int $whole): string
    {
        if ($whole < 1) {
            return '0.00';
        }

        return $this->roundPercent(($part / $whole) * 100);
    }

    private function roundPercent(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    /**
     * @return list<string>
     */
    private function ticketLinks(int $agentId, string $from, string $to): array
    {
        return Ticket::query()->where('owner_id', $agentId)->whereDate('created_at', '>=', $from)->whereDate('created_at', '<=', $to)->orderBy('id')->pluck('id')->map(fn ($id) => '/tickets/'.$id)->all();
    }

    private function event(QaEvaluation $evaluation, ?string $from, string $to, User $actor): void
    {
        QaEvent::query()->create([
            'qa_evaluation_id' => $evaluation->id,
            'from_status' => $from,
            'to_status' => $to,
            'actor_id' => $actor->id,
        ]);
    }
}
