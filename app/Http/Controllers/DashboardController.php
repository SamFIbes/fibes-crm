<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Reports\ReportDesk;
use App\Services\Reports\SalesFunnel;
use App\Services\Settings\CommercialSettings;
use App\Support\Access\AccessGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request, AccessGate $access, ReportDesk $reports, CommercialSettings $commercial): Response
    {
        $actor = $access->effective($request);
        abort_unless($actor instanceof User, 403);
        $from = $request->string('from')->toString() ?: $reports->defaultFrom();
        $to = $request->string('to')->toString() ?: $reports->defaultTo();

        return Inertia::render('Dashboard', [
            'home' => $reports->home($actor),
            'tiles' => $reports->tiles($actor, $from, $to),
            'funnelAllowed' => app(SalesFunnel::class)->allows($actor),
            'funnel' => Inertia::defer(fn () => $this->funnel($actor, $from, $to, $request->integer('funnel_pipeline') ?: null), 'funnel'),
            'choices' => $reports->allowedKeys($actor),
            'from' => $from,
            'to' => $to,
            'refresh' => max(5, (int) $commercial->get('report_refresh_seconds')),
        ]);
    }

    public function layout(Request $request, AccessGate $access, ReportDesk $reports): RedirectResponse
    {
        $actor = $access->effective($request);
        abort_unless($actor instanceof User, 403);
        $data = $request->validate(['keys' => ['array'], 'keys.*' => ['string']]);
        $reports->saveLayout($actor, $data['keys'] ?? []);

        return back()->with('status', __('ui.reports.saved'));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function funnel(User $actor, string $from, string $to, ?int $pipelineId): ?array
    {
        $desk = app(SalesFunnel::class);

        if (! $desk->allows($actor)) {
            return null;
        }

        try {
            return $desk->build($actor, $from, $to, $pipelineId);
        } catch (\Throwable $error) {
            report($error);

            return ['error' => true];
        }
    }
}
