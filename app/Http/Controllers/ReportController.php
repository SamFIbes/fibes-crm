<?php

namespace App\Http\Controllers;

use App\Jobs\BuildReportExport;
use App\Models\ReportExport;
use App\Models\ReportSchedule;
use App\Models\User;
use App\Services\Reports\ReportDesk;
use App\Services\Reports\ReportExporter;
use App\Services\Settings\CommercialSettings;
use App\Support\Access\AccessGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index(Request $request, AccessGate $access, ReportDesk $reports): Response
    {
        $actor = $this->actor($request, $access);
        $from = $request->string('from')->toString() ?: $reports->defaultFrom();
        $to = $request->string('to')->toString() ?: $reports->defaultTo();
        $keys = $reports->allowedKeys($actor);

        return Inertia::render('Reports/Index', [
            'reports' => array_map(fn (string $key) => [
                'key' => $key,
                'area' => ReportDesk::REPORTS[$key]['area'],
                'value' => $reports->value($key, $actor, $from, $to),
                'href' => '/reports/'.$key.'?from='.$from.'&to='.$to,
            ], $keys),
            'from' => $from,
            'to' => $to,
        ]);
    }

    public function show(Request $request, string $report, AccessGate $access, ReportDesk $reports): Response
    {
        $actor = $this->actor($request, $access);
        abort_unless($reports->allows($actor, $report), 403);
        $from = $request->string('from')->toString() ?: $reports->defaultFrom();
        $to = $request->string('to')->toString() ?: $reports->defaultTo();

        return Inertia::render('Reports/Show', [
            'report' => $report,
            'area' => ReportDesk::REPORTS[$report]['area'],
            'value' => $reports->value($report, $actor, $from, $to),
            'rows' => $reports->rows($report, $actor, $from, $to),
            'from' => $from,
            'to' => $to,
            'can_export' => $access->allows($actor, 'reports.export'),
            'drill' => ReportDesk::REPORTS[$report]['href'],
        ]);
    }

    public function export(Request $request, string $report, AccessGate $access, ReportDesk $reports, ReportExporter $exporter, CommercialSettings $commercial): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        abort_unless($access->allows($actor, 'reports.export'), 403);
        abort_unless($reports->allows($actor, $report), 403);
        $limit = max(1, (int) $commercial->get('report_export_per_hour'));
        $key = 'report-export:'.$actor->id;
        abort_if(RateLimiter::tooManyAttempts($key, $limit), 429);
        RateLimiter::hit($key, 3600);
        $data = $request->validate([
            'format' => ['required', 'in:csv,xlsx,pdf'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);
        $from = $data['from'] ?? $reports->defaultFrom();
        $to = $data['to'] ?? $reports->defaultTo();
        $export = ReportExport::query()->create([
            'user_id' => $actor->id,
            'report_key' => $report,
            'format' => $data['format'],
            'status' => 'pending',
        ]);
        $threshold = max(1, (int) $commercial->get('report_async_rows'));
        $count = count($reports->rows($report, $actor, $from, $to));

        if ($count >= $threshold) {
            BuildReportExport::dispatch($export->id, $from, $to);

            return back()->with('status', __('ui.reports.queued'));
        }

        $exporter->build($export, $from, $to, $request);

        return back()->with('link', $exporter->link($export->fresh()));
    }

    public function schedule(Request $request, string $report, AccessGate $access, ReportDesk $reports): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        abort_unless($reports->allows($actor, $report), 403);
        $data = $request->validate([
            'cadence' => ['required', 'in:daily,weekly'],
            'hour' => ['required', 'integer', 'min:0', 'max:23'],
            'weekday' => ['nullable', 'integer', 'min:1', 'max:7'],
        ]);
        ReportSchedule::query()->create([
            'user_id' => $actor->id,
            'report_key' => $report,
            'cadence' => $data['cadence'],
            'hour' => $data['hour'],
            'weekday' => $data['weekday'] ?? 1,
        ]);

        return back()->with('status', __('ui.reports.saved'));
    }

    public function download(Request $request, ReportExport $export): StreamedResponse
    {
        abort_unless($request->hasValidSignature(), 403);
        abort_unless($export->status === 'ready' && $export->path && $export->expires_at && $export->expires_at->isFuture(), 403);
        abort_unless(Storage::disk('local')->exists($export->path), 404);

        return Storage::disk('local')->download($export->path, $export->report_key.'.'.$export->format);
    }

    private function actor(Request $request, AccessGate $access): User
    {
        $actor = $access->effective($request);
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
