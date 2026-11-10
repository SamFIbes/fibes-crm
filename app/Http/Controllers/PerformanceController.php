<?php

namespace App\Http\Controllers;

use App\Models\CommissionEntry;
use App\Models\Target;
use App\Models\Team;
use App\Models\User;
use App\Services\Performance\CommissionDesk;
use App\Support\Access\AccessGate;
use App\Support\CsvCell;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PerformanceController extends Controller
{
    public function index(Request $request, AccessGate $access, CommissionDesk $desk): Response
    {
        $actor = $this->actor($request, $access);
        $period = $request->string('period')->toString() ?: 'month';

        if (! in_array($period, ['day', 'week', 'month'], true)) {
            $period = 'month';
        }

        return Inertia::render('Performance/Index', [
            'entries' => $desk->visible($actor)->latest('id')->limit(40)->get()->map(fn (CommissionEntry $row) => [
                'id' => $row->id,
                'user_id' => $row->user_id,
                'name' => (string) (User::query()->whereKey($row->user_id)->value('name') ?: ''),
                'kind' => $row->kind,
                'amount' => (string) $row->amount,
                'status' => $row->status,
                'period' => $row->period?->toDateString(),
            ])->values(),
            'targets' => Target::query()->whereNull('archived_at')->latest('id')->limit(20)->get()->map(fn (Target $target) => [
                'id' => $target->id,
                'metric' => $target->metric,
                'subject_type' => $target->subject_type,
                'value' => (string) $target->value,
                'progress' => $desk->progressFor($target),
                'period' => $target->period,
            ])->values(),
            'leaders' => $desk->leaderboard($period, $request->integer('team') ?: null),
            'period' => $period,
            'teams' => Team::query()->orderBy('name_en')->get(['id', 'name_en', 'name_ar']),
            'people' => $this->people($actor, $access),
            'metrics' => CommissionDesk::METRICS,
            'can_set' => $access->allows($actor, 'targets.set'),
            'can_export' => $access->scopeFor($actor, 'commissions.view') === 'all',
            'statement' => bcadd((string) ($desk->visible($actor)->whereDate('period', now()->startOfMonth()->toDateString())->sum('amount') ?: '0'), '0', 2),
            'tv_token' => $request->session()->get('tv_token'),
        ]);
    }

    public function target(Request $request, AccessGate $access, CommissionDesk $desk): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        $data = $request->validate([
            'period' => ['required', 'in:month,week'],
            'subject_type' => ['required', 'in:user,team,role'],
            'subject_id' => ['nullable', 'integer'],
            'subject_key' => ['nullable', 'string', 'max:40'],
            'metric' => ['required', 'string', 'max:40'],
            'value' => ['required', 'numeric'],
            'period_start' => ['required', 'date'],
        ]);
        $desk->setTarget($actor, $data, $request);

        return back()->with('status', __('ui.performance.saved'));
    }

    public function adjust(Request $request, AccessGate $access, CommissionDesk $desk): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        abort_unless($access->allows($actor, 'commissions.view'), 403);
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'amount' => ['required', 'numeric'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $desk->requestAdjustment($actor, (int) $data['user_id'], (string) $data['amount'], $data['reason'], $request);

        return back()->with('status', __('ui.performance.saved'));
    }

    public function token(Request $request, AccessGate $access, CommissionDesk $desk): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        abort_unless($access->allows($actor, 'targets.set') || $access->allows($actor, 'settings.manage'), 403);

        return back()->with('tv_token', $desk->issueToken($actor, $request));
    }

    public function export(Request $request, AccessGate $access, CommissionDesk $desk): StreamedResponse
    {
        $actor = $this->actor($request, $access);
        abort_unless($access->scopeFor($actor, 'commissions.view') === 'all', 403);
        $rows = $desk->visible($actor)->orderBy('id')->get();
        $desk->auditExport($actor, $rows->count(), $request);

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['user_id', 'period', 'kind', 'amount', 'currency', 'status']);

            foreach ($rows as $row) {
                fputcsv($out, CsvCell::row([$row->user_id, $row->period?->toDateString(), $row->kind, $row->amount, $row->currency, $row->status]));
            }

            fclose($out);
        }, 'commissions.csv', ['Content-Type' => 'text/csv']);
    }

    public function tv(Request $request, CommissionDesk $desk): Response
    {
        abort_unless($request->attributes->get('tv_token') === true, 403);

        return Inertia::render('Tv/Sales', [
            'feed' => $desk->feed(),
            'token' => (string) $request->query('token'),
        ]);
    }

    public function feed(Request $request, CommissionDesk $desk): JsonResponse
    {
        abort_unless($request->attributes->get('tv_token') === true, 403);

        return response()->json($desk->feed());
    }

    private function actor(Request $request, AccessGate $access): User
    {
        $actor = $access->effective($request);
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function people(User $actor, AccessGate $access): array
    {
        $query = User::query()->orderBy('name');

        if ($access->scopeFor($actor, 'targets.set') === 'team' || $access->scopeFor($actor, 'commissions.view') === 'team') {
            $teamIds = $actor->teams()->pluck('teams.id');
            $query->where(function ($inner) use ($actor, $teamIds) {
                $inner->whereKey($actor->id)->orWhereHas('teams', fn ($teams) => $teams->whereIn('teams.id', $teamIds));
            });
        }

        if ($access->scopeFor($actor, 'commissions.view') === 'own' && $access->scopeFor($actor, 'targets.set') === null) {
            $query->whereKey($actor->id);
        }

        return $query->limit(80)->get(['id', 'name'])->map(fn (User $user) => [
            'id' => $user->id,
            'name' => $user->name,
        ])->all();
    }
}
