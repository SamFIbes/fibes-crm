<?php

namespace App\Http\Controllers;

use App\Models\Area;
use App\Models\Job;
use App\Models\User;
use App\Models\UserSkill;
use App\Services\Coverage\Geo;
use App\Services\Jobs\FieldWork;
use App\Support\Access\AccessGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class DispatchController extends Controller
{
    public function index(Request $request, FieldWork $work, AccessGate $access, Geo $geo): Response
    {
        $actor = $this->actor($request, $access);
        $date = Carbon::parse((string) ($request->input('date') ?: now()->toDateString()), (string) config('app.timezone'))->startOfDay();
        $mode = $request->string('mode')->toString() === 'week' ? 'week' : 'day';
        $areaId = $request->integer('area_id') ?: null;
        $end = $mode === 'week' ? $date->copy()->addDays(7) : $date->copy()->addDay();

        $jobs = $work->query($actor)->with(['customer.area', 'customer.phones', 'technician:id,name', 'service:id,service_type', 'checks'])
            ->when($areaId, fn ($query) => $query->whereHas('customer', fn ($customers) => $customers->where('area_id', $areaId)))
            ->where(function ($query) use ($date, $end) {
                $query->whereNull('technician_id')
                    ->orWhere(function ($window) use ($date, $end) {
                        $window->where('window_start', '>=', $date)->where('window_start', '<', $end);
                    });
            })
            ->orderBy('window_start')
            ->get();

        $technicians = User::query()->whereIn('role', ['field_technician', 'installer'])->where('is_active', true)->orderBy('name')->get();
        $skills = UserSkill::query()->whereIn('user_id', $technicians->pluck('id'))->get()->groupBy('user_id');

        return Inertia::render('Dispatch/Index', [
            'date' => $date->toDateString(),
            'mode' => $mode,
            'area_id' => $areaId,
            'areas' => Area::query()->orderBy('name_en')->get(['id', 'name_en', 'name_ar']),
            'skills' => FieldWork::SKILLS,
            'types' => FieldWork::TYPES,
            'unassigned' => $jobs->whereNull('technician_id')->map(fn (Job $job) => $work->present($job, $actor))->values(),
            'technicians' => $technicians->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'skills' => ($skills[$user->id] ?? collect())->pluck('skill')->values(),
                'jobs' => $jobs->where('technician_id', $user->id)->map(fn (Job $job) => $work->present($job, $actor))->values(),
            ])->values(),
            'pins' => $this->pins($jobs, $geo),
            'technicians_here' => $technicians->filter(fn (User $user) => $user->last_latitude !== null && $user->last_longitude !== null)->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'latitude' => (float) $user->last_latitude,
                'longitude' => (float) $user->last_longitude,
            ])->values(),
            'tiles' => (string) config('fibes.coverage.tile_url'),
        ]);
    }

    public function store(Request $request, FieldWork $work, AccessGate $access): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:'.implode(',', FieldWork::TYPES)],
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'customer_service_id' => ['nullable', 'integer', 'exists:customer_services,id'],
            'ticket_id' => ['nullable', 'integer', 'exists:tickets,id'],
            'technician_id' => ['nullable', 'integer', 'exists:users,id'],
            'required_skill' => ['nullable', 'in:'.implode(',', FieldWork::SKILLS)],
            'window_start' => ['nullable', 'date'],
            'window_end' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $job = $work->create($data, $this->actor($request, $access), $request);

        return redirect()->route('dispatch')->with('status', __('ui.jobs.opened', ['number' => $job->number]));
    }

    public function update(Request $request, Job $job, FieldWork $work, AccessGate $access): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        abort_unless($work->query($actor)->whereKey($job->id)->exists(), 403);
        $data = $request->validate([
            'technician_id' => ['nullable', 'integer', 'exists:users,id'],
            'window_start' => ['nullable', 'date'],
            'window_end' => ['nullable', 'date'],
            'required_skill' => ['nullable', 'in:'.implode(',', FieldWork::SKILLS)],
            'status' => ['nullable', 'in:scheduled,rescheduled'],
        ]);
        $warnings = $work->schedule($job, $data, $actor, $request);
        $message = __('ui.jobs.scheduled');

        if (in_array('overlap', $warnings, true)) {
            $message .= ' '.__('ui.jobs.overlap');
        }

        if (in_array('skill', $warnings, true)) {
            $message .= ' '.__('ui.jobs.skill_missing');
        }

        return back()->with('status', trim($message));
    }

    public function skills(Request $request, User $user, AccessGate $access): RedirectResponse
    {
        abort_unless(in_array($user->role, ['field_technician', 'installer'], true), 422);
        $data = $request->validate([
            'skills' => ['array'],
            'skills.*' => ['in:'.implode(',', FieldWork::SKILLS)],
        ]);
        $user->skills()->delete();

        foreach ($data['skills'] ?? [] as $skill) {
            $user->skills()->create(['skill' => $skill]);
        }

        return back()->with('status', __('ui.jobs.skills_saved'));
    }

    /**
     * @param  Collection<int, Job>  $jobs
     * @return list<array{id: int, number: string|null, name: string|null, latitude: float, longitude: float, technician_id: int|null, order: int, maps: string|null}>
     */
    private function pins(Collection $jobs, Geo $geo): array
    {
        $located = $jobs->filter(fn (Job $job) => $job->customer?->latitude !== null && $job->customer?->longitude !== null);
        $depot = config('fibes.coverage.depot');
        $startLat = (float) ($depot['lat'] ?? 33.8938);
        $startLng = (float) ($depot['lng'] ?? 35.5018);
        $orders = [];

        foreach ($located->groupBy(fn (Job $job) => (string) ($job->technician_id ?? 'open')) as $group) {
            $points = $group->map(fn (Job $job) => [
                'id' => $job->id,
                'lat' => (float) $job->customer?->latitude,
                'lng' => (float) $job->customer?->longitude,
            ])->values()->all();
            $ids = $geo->nearestNeighbour($points, $startLat, $startLng);

            foreach ($ids as $index => $id) {
                $orders[$id] = $index + 1;
            }
        }

        return $located->map(fn (Job $job) => [
            'id' => $job->id,
            'number' => $job->number,
            'name' => $job->customer?->name_en,
            'latitude' => (float) $job->customer?->latitude,
            'longitude' => (float) $job->customer?->longitude,
            'technician_id' => $job->technician_id,
            'order' => $orders[$job->id] ?? 1,
            'maps' => 'https://www.google.com/maps/dir/?api=1&destination='.$job->customer?->latitude.','.$job->customer?->longitude,
        ])->values()->all();
    }

    private function actor(Request $request, AccessGate $access): User
    {
        $actor = $access->effective($request);
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
