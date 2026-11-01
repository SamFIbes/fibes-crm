<?php

namespace App\Http\Controllers;

use App\Models\Area;
use App\Models\District;
use App\Models\Governorate;
use App\Models\Incident;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Tickets\IncidentRecords;
use App\Support\Access\AccessGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class IncidentController extends Controller
{
    public function index(Request $request, IncidentRecords $records): Response
    {
        $status = (string) $request->string('status');
        $query = Incident::query()->with(['area', 'district', 'governorate'])->latest();

        if ($status !== '') {
            $query->where('status', $status);
        }

        return Inertia::render('Incidents/Index', [
            'incidents' => $query->limit(100)->get()->map(fn (Incident $incident) => $records->present($incident))->values(),
            'filters' => ['status' => $status],
            'geography' => $this->geography(),
            'service_types' => array_keys(config('fibes.service_types')),
        ]);
    }

    public function store(Request $request, IncidentRecords $records, AccessGate $access): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'governorate_id' => ['nullable', 'integer', 'exists:governorates,id'],
            'district_id' => ['nullable', 'integer', 'exists:districts,id'],
            'area_id' => ['nullable', 'integer', 'exists:areas,id'],
            'service_type' => ['nullable', 'string', 'max:40'],
            'network_element' => ['nullable', 'string', 'max:120'],
            'network_node_id' => ['nullable', 'integer', 'exists:network_nodes,id'],
            'summary' => ['nullable', 'string', 'max:2000'],
        ]);
        $incident = $records->create($data, $this->actor($request, $access), $request);

        return redirect()->route('incidents.show', $incident)->with('status', __('ui.incidents.opened'));
    }

    public function show(Incident $incident, IncidentRecords $records): Response
    {
        return Inertia::render('Incidents/Show', [
            'incident' => $records->present($incident, true),
            'service_types' => array_keys(config('fibes.service_types')),
        ]);
    }

    public function link(Request $request, Incident $incident, IncidentRecords $records, AccessGate $access): RedirectResponse
    {
        $data = $request->validate(['ticket' => ['required', 'string', 'max:40']]);
        $key = $data['ticket'];
        $ticket = Ticket::query()
            ->where('number', $key)
            ->when(ctype_digit($key), fn ($query) => $query->orWhere('id', (int) $key))
            ->first();
        if (! $ticket instanceof Ticket) {
            throw ValidationException::withMessages(['ticket' => __('ui.incidents.missing')]);
        }
        $records->link($incident, $ticket, $this->actor($request, $access), $request);

        return back()->with('status', __('ui.incidents.linked'));
    }

    public function unlink(Request $request, Incident $incident, Ticket $ticket, IncidentRecords $records, AccessGate $access): RedirectResponse
    {
        $records->unlink($incident, $ticket, $this->actor($request, $access), $request);

        return back()->with('status', __('ui.incidents.unlinked'));
    }

    public function linkMatching(Request $request, Incident $incident, IncidentRecords $records, AccessGate $access): RedirectResponse
    {
        $count = $records->linkMatching($incident, $this->actor($request, $access), $request);

        return back()->with('status', __('ui.incidents.bulk_linked', ['count' => $count]));
    }

    public function resolve(Request $request, Incident $incident, IncidentRecords $records, AccessGate $access): RedirectResponse
    {
        $data = $request->validate([
            'mode' => ['required', 'in:resolve,update'],
            'note' => ['required', 'string', 'max:2000'],
        ]);
        $records->resolve($incident, $data['mode'], $data['note'], $this->actor($request, $access), $request);

        return back()->with('status', __('ui.incidents.resolved'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function geography(): array
    {
        return Governorate::query()->with(['districts.areas'])->orderBy('name_en')->get()->map(fn (Governorate $governorate) => [
            'id' => $governorate->id,
            'name_en' => $governorate->name_en,
            'name_ar' => $governorate->name_ar,
            'districts' => $governorate->districts->sortBy('name_en')->values()->map(fn (District $district) => [
                'id' => $district->id,
                'name_en' => $district->name_en,
                'name_ar' => $district->name_ar,
                'areas' => $district->areas->sortBy('name_en')->values()->map(fn (Area $area) => [
                    'id' => $area->id,
                    'name_en' => $area->name_en,
                    'name_ar' => $area->name_ar,
                ])->all(),
            ])->all(),
        ])->all();
    }

    private function actor(Request $request, AccessGate $access): User
    {
        $actor = $access->effective($request);
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
