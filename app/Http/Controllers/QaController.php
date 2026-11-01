<?php

namespace App\Http\Controllers;

use App\Models\QaEvaluation;
use App\Models\QaForm;
use App\Models\QaScript;
use App\Models\TicketEscalation;
use App\Models\User;
use App\Services\Qa\QaDesk;
use App\Support\Access\AccessGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class QaController extends Controller
{
    public function index(Request $request, AccessGate $access, QaDesk $qa): Response
    {
        $actor = $this->actor($request, $access);
        $agentId = $this->agentId($request, $actor, $access);
        $from = $request->string('from')->toString() ?: now()->startOfMonth()->toDateString();
        $to = $request->string('to')->toString() ?: now()->toDateString();
        $evaluations = $qa->visible($actor)->latest('id')->limit(40)->get();

        return Inertia::render('Qa/Index', [
            'evaluations' => $evaluations->map(fn (QaEvaluation $row) => [
                'id' => $row->id,
                'agent_id' => $row->agent_id,
                'subject_type' => $row->subject_type,
                'subject_id' => $row->subject_id,
                'total' => (string) $row->total_pct,
                'critical_fail' => (bool) $row->critical_fail,
                'status' => $row->status,
                'form_version' => $row->form_version,
                'notes' => $row->coaching_notes,
                'href' => $this->href($row),
            ])->values(),
            'calibrations' => $evaluations
                ->unique(fn (QaEvaluation $row) => $row->subject_type.'#'.$row->subject_id)
                ->map(function (QaEvaluation $row) use ($qa) {
                    $report = $qa->calibration((string) $row->subject_type, (int) $row->subject_id);

                    return $report === null ? null : $report + [
                        'subject_type' => $row->subject_type,
                        'subject_id' => $row->subject_id,
                    ];
                })
                ->filter()
                ->values(),
            'form' => QaForm::query()->where('key', 'call')->where('is_current', true)->first(['id', 'version', 'name_en', 'name_ar', 'criteria']),
            'scripts' => QaScript::query()->where('is_current', true)->orderBy('call_type')->get(['id', 'call_type', 'version', 'name_en', 'name_ar']),
            'kpis' => $qa->kpis($agentId, $from, $to),
            'status_time' => $qa->statusTime($agentId, $from, $to),
            'from' => $from,
            'to' => $to,
            'agent_id' => $agentId,
            'agents' => $access->allows($actor, 'qa.score') || $access->scopeFor($actor, 'qa.view') === 'all'
                ? User::query()->orderBy('name')->get(['id', 'name'])
                : [],
            'can_score' => $access->allows($actor, 'qa.score'),
            'can_revise' => $access->allows($actor, 'qa.score') && $actor->role !== 'sales_manager',
            'own' => $actor->id,
        ]);
    }

    public function score(Request $request, AccessGate $access, QaDesk $qa): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        $data = $request->validate([
            'subject_type' => ['required', 'in:interaction,ticket,escalation,retention'],
            'subject_id' => ['required', 'integer'],
            'agent_id' => ['required', 'integer', 'exists:users,id'],
            'scores' => ['required', 'array'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $qa->score($data['subject_type'], (int) $data['subject_id'], (int) $data['agent_id'], $data['scores'], $data['notes'] ?? null, $actor, $request);

        return back()->with('status', __('ui.qa.saved'));
    }

    public function acknowledge(Request $request, QaEvaluation $evaluation, AccessGate $access, QaDesk $qa): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        abort_unless($qa->visible($actor)->whereKey($evaluation->id)->exists(), 404);
        $qa->acknowledge($evaluation, $actor, $request);

        return back()->with('status', __('ui.qa.saved'));
    }

    public function revise(Request $request, AccessGate $access, QaDesk $qa): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        abort_unless($actor->role !== 'sales_manager', 403);
        $form = QaForm::query()->where('key', 'call')->where('is_current', true)->firstOrFail();
        $data = $request->validate(['criteria' => ['required', 'array', 'min:1']]);
        $qa->reviseForm($form, $data['criteria'], $actor, $request);

        return back()->with('status', __('ui.qa.saved'));
    }

    public function sample(Request $request, AccessGate $access, QaDesk $qa): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        $count = $qa->sample(now(), $actor);

        return back()->with('status', __('ui.qa.sampled').' '.$count);
    }

    public function status(Request $request, AccessGate $access, QaDesk $qa): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        $data = $request->validate(['status' => ['required', 'string', 'max:20']]);
        $qa->setStatus($actor, $data['status'], $actor, $request);

        return back()->with('status', __('ui.qa.saved'));
    }

    private function actor(Request $request, AccessGate $access): User
    {
        $actor = $access->effective($request);
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    private function agentId(Request $request, User $actor, AccessGate $access): int
    {
        $requested = $request->integer('agent');

        if ($requested > 0 && ($access->allows($actor, 'qa.score') || $access->scopeFor($actor, 'qa.view') === 'all')) {
            return $requested;
        }

        return $actor->id;
    }

    private function href(QaEvaluation $row): string
    {
        if ($row->subject_type === 'escalation') {
            $ticketId = TicketEscalation::query()->whereKey($row->subject_id)->value('ticket_id');

            return '/tickets/'.($ticketId ?: $row->subject_id);
        }

        return match ($row->subject_type) {
            'ticket' => '/tickets/'.$row->subject_id,
            'retention' => '/retention',
            default => '/calls',
        };
    }
}
