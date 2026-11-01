<?php

namespace App\Http\Controllers;

use App\Models\CoverageItem;
use App\Models\User;
use App\Services\Coverage\CoverageDesk;
use App\Support\Access\AccessGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CoverageController extends Controller
{
    public function index(Request $request, CoverageDesk $coverage, AccessGate $access): Response
    {
        $actor = $this->actor($request, $access);
        abort_unless(
            $access->allows($actor, 'coverage.view') || $access->allows($actor, 'coverage.check') || $access->allows($actor, 'coverage.edit'),
            403,
        );

        return Inertia::render('Coverage/Index', [
            'items' => CoverageItem::query()->latest('id')->limit(200)->get()->map(fn (CoverageItem $item) => [
                'id' => $item->id,
                'name' => $item->name,
                'kind' => $item->kind,
                'service_type' => $item->service_type,
                'status' => $item->status,
                'latitude' => $item->latitude,
                'longitude' => $item->longitude,
                'capacity_total' => $item->capacity_total,
                'capacity_used' => $item->capacity_used,
                'free' => $item->freePorts(),
            ]),
            'limits' => $coverage->limits(),
            'can_check' => $access->allows($actor, 'coverage.check'),
            'can_edit' => $access->allows($actor, 'coverage.edit'),
            'services' => array_keys(config('fibes.service_types')),
            'preview' => array_values((array) $request->session()->get('coverage_preview', [])),
        ]);
    }

    public function check(Request $request, CoverageDesk $coverage, AccessGate $access): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        abort_unless($access->allows($actor, 'coverage.check'), 403);
        $data = $request->validate([
            'subject' => ['required', 'in:lead,opportunity'],
            'subject_id' => ['required', 'integer'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
            'location' => ['nullable', 'string', 'max:500'],
            'address' => ['nullable', 'string', 'max:240'],
        ]);
        $lat = $data['latitude'] ?? null;
        $lng = $data['longitude'] ?? null;

        if (($lat === null || $lng === null) && filled($data['location'] ?? null)) {
            $point = $this->point((string) $data['location']);

            if ($point === null) {
                return back()->withErrors(['location' => __('ui.coverage.pin_needed')]);
            }

            [$lat, $lng] = $point;
        }

        if (! is_numeric($lat) || ! is_numeric($lng)) {
            return back()->withErrors(['location' => __('ui.coverage.pin_needed')]);
        }

        $check = $coverage->record($data['subject'], (int) $data['subject_id'], (float) $lat, (float) $lng, $data['address'] ?? null, $actor, $request);

        return back()->with('status', __('ui.coverage.result_'.$check->result));
    }

    public function store(Request $request, CoverageDesk $coverage, AccessGate $access): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        abort_unless($access->allows($actor, 'coverage.edit'), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'kind' => ['required', 'in:building,polygon,fat,olt,tower,sector'],
            'service_type' => ['required', 'string', 'max:30'],
            'latitude' => ['required', 'numeric'],
            'longitude' => ['required', 'numeric'],
            'capacity_total' => ['nullable', 'integer', 'min:0'],
            'capacity_used' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', 'in:live,planned,full'],
            'azimuth' => ['nullable', 'numeric'],
            'beamwidth' => ['nullable', 'numeric'],
            'range_m' => ['nullable', 'numeric'],
        ]);
        $coverage->storeItem($data, null, $actor);

        return back()->with('status', __('ui.coverage.saved'));
    }

    public function publish(Request $request, CoverageItem $item, CoverageDesk $coverage, AccessGate $access): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        abort_unless($access->allows($actor, 'coverage.edit'), 403);
        $count = $coverage->publish($item, $actor, $request);

        return back()->with('status', __('ui.coverage.published', ['count' => $count]));
    }

    public function import(Request $request, CoverageDesk $coverage, AccessGate $access): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        abort_unless($access->allows($actor, 'coverage.edit'), 403);
        $data = $request->validate([
            'file' => ['required', 'file', 'max:8192'],
            'apply' => ['nullable', 'boolean'],
        ]);
        $file = $data['file'];
        $result = $coverage->import($file->getContent(), $file->getClientOriginalName(), (bool) ($data['apply'] ?? false), $actor, $request);

        return back()->with('coverage_preview', $result['preview'])->with('status', __('ui.coverage.saved'));
    }

    /**
     * @return array{0: float, 1: float}|null
     */
    private function point(string $text): ?array
    {
        $patterns = [
            '/@(-?\d+\.\d+),(-?\d+\.\d+)/',
            '/[?&]q=(-?\d+\.\d+),(-?\d+\.\d+)/',
            '/(-?\d{1,2}\.\d+)\s*,\s*(-?\d{1,3}\.\d+)/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $match) === 1) {
                return [(float) $match[1], (float) $match[2]];
            }
        }

        return null;
    }

    private function actor(Request $request, AccessGate $access): User
    {
        $actor = $access->effective($request);
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
