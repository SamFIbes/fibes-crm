<?php

namespace App\Http\Controllers;

use App\Models\Area;
use App\Models\District;
use App\Models\Governorate;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Access\AccessGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GeographyController extends Controller
{
    public function index(Request $request, AccessGate $access): Response
    {
        $actor = $access->effective($request);

        return Inertia::render('Geography/Index', [
            'governorates' => Governorate::query()
                ->with(['districts.areas'])
                ->orderBy('name_en')
                ->get(),
            'can_manage' => $actor instanceof User && $access->allows($actor, 'geography.manage'),
        ]);
    }

    public function storeGovernorate(Request $request, AccessGate $access, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'name_en' => ['required', 'string', 'max:120'],
            'name_ar' => ['required', 'string', 'max:120'],
        ]);
        $row = Governorate::query()->create($data);
        $this->audit($audit, $access, $request, 'geography.governorate.created', $row->only(['id', 'name_en', 'name_ar']));

        return back()->with('status', __('ui.geography.saved'));
    }

    public function updateGovernorate(Request $request, Governorate $governorate, AccessGate $access, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'name_en' => ['required', 'string', 'max:120'],
            'name_ar' => ['required', 'string', 'max:120'],
        ]);
        $before = $governorate->only(['name_en', 'name_ar']);
        $governorate->update($data);
        $this->audit($audit, $access, $request, 'geography.governorate.updated', [
            'id' => $governorate->id,
            'before' => $before,
            'after' => $governorate->only(['name_en', 'name_ar']),
        ]);

        return back()->with('status', __('ui.geography.saved'));
    }

    public function storeDistrict(Request $request, AccessGate $access, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'governorate_id' => ['required', 'integer', 'exists:governorates,id'],
            'name_en' => ['required', 'string', 'max:120'],
            'name_ar' => ['required', 'string', 'max:120'],
        ]);
        $row = District::query()->create($data);
        $this->audit($audit, $access, $request, 'geography.district.created', $row->only(['id', 'governorate_id', 'name_en', 'name_ar']));

        return back()->with('status', __('ui.geography.saved'));
    }

    public function updateDistrict(Request $request, District $district, AccessGate $access, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'name_en' => ['required', 'string', 'max:120'],
            'name_ar' => ['required', 'string', 'max:120'],
        ]);
        $before = $district->only(['name_en', 'name_ar']);
        $district->update($data);
        $this->audit($audit, $access, $request, 'geography.district.updated', [
            'id' => $district->id,
            'before' => $before,
            'after' => $district->only(['name_en', 'name_ar']),
        ]);

        return back()->with('status', __('ui.geography.saved'));
    }

    public function storeArea(Request $request, AccessGate $access, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'district_id' => ['required', 'integer', 'exists:districts,id'],
            'name_en' => ['required', 'string', 'max:120'],
            'name_ar' => ['required', 'string', 'max:120'],
        ]);
        $row = Area::query()->create($data);
        $this->audit($audit, $access, $request, 'geography.area.created', $row->only(['id', 'district_id', 'name_en', 'name_ar']));

        return back()->with('status', __('ui.geography.saved'));
    }

    public function updateArea(Request $request, Area $area, AccessGate $access, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'name_en' => ['required', 'string', 'max:120'],
            'name_ar' => ['required', 'string', 'max:120'],
        ]);
        $before = $area->only(['name_en', 'name_ar']);
        $area->update($data);
        $this->audit($audit, $access, $request, 'geography.area.updated', [
            'id' => $area->id,
            'before' => $before,
            'after' => $area->only(['name_en', 'name_ar']),
        ]);

        return back()->with('status', __('ui.geography.saved'));
    }

    public function destroyArea(Request $request, Area $area, AccessGate $access, AuditLogger $audit): RedirectResponse
    {
        $this->audit($audit, $access, $request, 'geography.area.deleted', $area->only(['id', 'district_id', 'name_en', 'name_ar']));
        $area->delete();

        return back()->with('status', __('ui.geography.removed'));
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function audit(AuditLogger $audit, AccessGate $access, Request $request, string $event, array $metadata): void
    {
        $actor = $access->effective($request);
        $audit->record($event, $actor instanceof User ? $actor : null, $metadata, $request);
    }
}
