<?php

namespace App\Http\Controllers;

use App\Models\Job;
use App\Models\JobCheck;
use App\Models\StockItem;
use App\Models\StockSerial;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\SessionRegistry;
use App\Services\Files\FileDesk;
use App\Services\Inventory\StockLedger;
use App\Services\Jobs\FieldWork;
use App\Services\Payments\PaymentDesk;
use App\Services\Settings\CommercialSettings;
use App\Support\Access\AccessGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TechController extends Controller
{
    public function index(Request $request, FieldWork $work, StockLedger $stock, AccessGate $access, CommercialSettings $commercial): Response
    {
        $actor = $this->actor($request, $access);
        $jobs = $work->query($actor)
            ->with(['customer.area', 'customer.phones', 'technician:id,name', 'service:id,service_type', 'checks'])
            ->whereNotIn('status', ['done', 'failed'])
            ->orderBy('window_start')
            ->limit(30)
            ->get()
            ->map(fn (Job $job) => $work->present($job, $actor, true));

        return Inertia::render('Tech/Index', [
            'jobs' => $jobs,
            'failures' => FieldWork::FAILURES,
            'van' => $stock->vanFor($actor),
            'can_collect' => $access->allows($actor, 'payments.collect'),
            'field_cash' => (bool) $commercial->get('field_cash'),
        ]);
    }

    public function unlockCash(Request $request, AccessGate $access, PaymentDesk $payments): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        $data = $request->validate(['password' => ['required', 'string']]);
        $payments->unlock($actor, $data['password'], $request);

        return back()->with('status', __('ui.payments.unlocked'));
    }

    public function confirm(Request $request): RedirectResponse
    {
        $data = $request->validate(['password' => ['required', 'string']]);
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        if (! Hash::check($data['password'], (string) $actor->password)) {
            throw ValidationException::withMessages(['password' => __('ui.auth.failed')]);
        }

        $request->session()->put('auth.confirmed_at', now()->getTimestamp());

        return back()->with('status', __('ui.security.confirmed'));
    }

    public function cash(Request $request, Job $job, FieldWork $work, AccessGate $access, PaymentDesk $payments, SessionRegistry $sessions): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        abort_unless($work->query($actor)->whereKey($job->id)->exists(), 403);
        $this->reauth($request, $sessions);
        abort_unless($job->customer_id, 422);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['required', 'in:USD,LBP'],
            'password' => ['nullable', 'string'],
        ]);
        $payments->record($actor, [
            'customer_id' => $job->customer_id,
            'amount' => $data['amount'],
            'currency' => $data['currency'],
            'method' => 'cash',
            'password' => $data['password'] ?? null,
        ], $request, 'field');

        return back()->with('status', __('ui.payments.saved'));
    }

    public function stock(Request $request, Job $job, FieldWork $work, StockLedger $stock, AccessGate $access): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        abort_unless($work->query($actor)->whereKey($job->id)->exists(), 403);
        $data = $request->validate([
            'item_id' => ['required', 'integer', 'exists:stock_items,id'],
            'quantity' => ['nullable', 'numeric'],
            'serial' => ['nullable', 'string', 'max:80'],
            'install' => ['nullable', 'boolean'],
        ]);
        $item = StockItem::query()->findOrFail($data['item_id']);
        $van = $stock->ensureVan($actor);
        abort_unless((int) $van->user_id === (int) $actor->id || $access->allows($actor, 'inventory.move'), 403);
        $serial = filled($data['serial'] ?? null)
            ? StockSerial::query()->where('item_id', $item->id)->where('serial', $data['serial'])->first()
            : null;
        $stock->issue($job, $item, $van, (string) ($data['quantity'] ?? '1'), $serial, $actor, $request, $request->header('Idempotency-Key'));

        if ($request->boolean('install') && $serial && $job->customer_service_id) {
            $stock->install($job, $serial->fresh(), (int) $job->customer_service_id, $actor, $request);
        }

        return back()->with('status', __('ui.inventory.issued'));
    }

    public function status(Request $request, Job $job, FieldWork $work, AccessGate $access): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        abort_unless($work->query($actor)->whereKey($job->id)->exists(), 403);
        abort_unless($this->canMove($actor, $access), 403);
        $data = $request->validate([
            'status' => ['required', 'in:'.implode(',', FieldWork::STATUSES)],
            'failure_reason' => ['nullable', 'in:'.implode(',', FieldWork::FAILURES)],
            'failure_note' => ['nullable', 'string', 'max:500'],
        ]);
        $work->changeStatus($job, $data['status'], $data, $actor, $request, $request->header('Idempotency-Key'));
        $this->rememberLocation($actor, $request);

        return back()->with('status', __('ui.jobs.status_saved'));
    }

    public function survey(Request $request, Job $job, FieldWork $work, AccessGate $access, AuditLogger $audit): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        abort_unless($work->query($actor)->whereKey($job->id)->exists(), 403);
        abort_unless($job->type === 'survey', 422);
        $data = $request->validate([
            'feasible' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:240'],
            'distance_m' => ['nullable', 'numeric'],
            'los' => ['nullable', 'string', 'max:40'],
            'signal_dbm' => ['nullable', 'numeric'],
            'cable_m' => ['nullable', 'numeric'],
            'mounting_point' => ['nullable', 'string', 'max:120'],
            'riser_ok' => ['nullable', 'boolean'],
            'power_ok' => ['nullable', 'boolean'],
            'materials' => ['nullable', 'string', 'max:500'],
            'recommended_service_type' => ['nullable', 'string', 'max:30'],
        ]);
        $photos = $job->files()->pluck('id')->all();
        $job->survey()->updateOrCreate(['job_id' => $job->id], [
            'feasible' => (bool) $data['feasible'],
            'reason' => $data['reason'] ?? null,
            'distance_m' => $data['distance_m'] ?? null,
            'los' => $data['los'] ?? null,
            'signal_dbm' => $data['signal_dbm'] ?? null,
            'cable_m' => $data['cable_m'] ?? null,
            'mounting_point' => $data['mounting_point'] ?? null,
            'riser_ok' => (bool) ($data['riser_ok'] ?? false),
            'power_ok' => (bool) ($data['power_ok'] ?? false),
            'materials' => filled($data['materials'] ?? null) ? ['note' => $data['materials']] : null,
            'recommended_service_type' => $data['recommended_service_type'] ?? null,
            'photos' => $photos,
        ]);
        $audit->record('survey.saved', $actor, [
            'job_id' => $job->id,
            'feasible' => (bool) $data['feasible'],
            'customer_id' => $job->customer_id,
        ], $request);

        return back()->with('status', __('ui.coverage.survey_saved'));
    }

    public function check(Request $request, Job $job, JobCheck $check, FieldWork $work, AccessGate $access): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        abort_unless($check->job_id === $job->id, 404);
        abort_unless($work->query($actor)->whereKey($job->id)->exists(), 403);
        $data = $request->validate(['done' => ['required', 'boolean']]);
        $work->toggleCheck($check, (bool) $data['done'], $actor, $request);

        return back();
    }

    public function notes(Request $request, Job $job, FieldWork $work, AccessGate $access, AuditLogger $audit): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        abort_unless($work->query($actor)->whereKey($job->id)->exists(), 403);
        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:5000'],
            'materials' => ['nullable', 'string', 'max:5000'],
        ]);
        $job->fill($data)->save();
        $audit->record('job.notes', $actor, ['job_id' => $job->id, 'customer_id' => $job->customer_id], $request);

        return back()->with('status', __('ui.jobs.notes_saved'));
    }

    public function photo(Request $request, Job $job, FieldWork $work, AccessGate $access, FileDesk $files): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        abort_unless($work->query($actor)->whereKey($job->id)->exists(), 403);
        $upload = $request->validate([
            'file' => ['required', 'file', 'max:'.(int) config('fibes.attachments.max_kilobytes'), 'mimes:jpg,jpeg,png,webp'],
        ])['file'];
        $files->store($upload, $actor, $request, $job, 'job.photo', [
            'job_id' => $job->id,
            'customer_id' => $job->customer_id,
        ]);

        return back()->with('status', __('ui.jobs.photo_saved'));
    }

    public function signature(Request $request, Job $job, FieldWork $work, AccessGate $access, FileDesk $files, SessionRegistry $sessions): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        abort_unless($work->query($actor)->whereKey($job->id)->exists(), 403);
        $this->reauth($request, $sessions);
        $data = $request->validate([
            'signed_by' => ['required', 'string', 'max:160'],
            'file' => ['required', 'file', 'max:2048', 'mimes:png,jpg,jpeg,webp'],
        ]);
        $file = $files->store($data['file'], $actor, $request, $job, 'job.signed', [
            'job_id' => $job->id,
            'customer_id' => $job->customer_id,
        ]);
        $job->signed_by = $data['signed_by'];
        $job->signature_attachment_id = $file->id;
        $job->save();

        return back()->with('status', __('ui.jobs.signed'));
    }

    private function reauth(Request $request, SessionRegistry $sessions): void
    {
        if (! $sessions->recentlyConfirmed($request)) {
            throw ValidationException::withMessages(['reauth' => __('ui.security.reauth')]);
        }
    }

    private function canMove(User $actor, AccessGate $access): bool
    {
        return $access->allows($actor, 'jobs.complete') || $access->allows($actor, 'jobs.schedule');
    }

    private function rememberLocation(User $actor, Request $request): void
    {
        if (! is_numeric($request->input('latitude')) || ! is_numeric($request->input('longitude'))) {
            return;
        }

        $actor->forceFill([
            'last_latitude' => $request->input('latitude'),
            'last_longitude' => $request->input('longitude'),
            'last_located_at' => now(),
        ])->save();
    }

    private function actor(Request $request, AccessGate $access): User
    {
        $actor = $access->effective($request);
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
