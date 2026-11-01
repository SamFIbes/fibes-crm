<?php

namespace App\Http\Controllers;

use App\Models\Area;
use App\Models\NetworkNode;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Jobs\FieldWork;
use App\Support\Access\AccessGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NetworkController extends Controller
{
    public function index(AccessGate $access, Request $request): Response
    {
        $actor = $access->effective($request);

        return Inertia::render('Network/Index', [
            'nodes' => NetworkNode::query()->with('area:id,name_en,name_ar')->orderBy('name')->get()->map(fn (NetworkNode $node) => [
                'id' => $node->id,
                'parent_id' => $node->parent_id,
                'kind' => $node->kind,
                'name' => $node->name,
                'area_en' => $node->area?->name_en,
                'area_ar' => $node->area?->name_ar,
                'notes' => $node->notes,
            ]),
            'areas' => Area::query()->orderBy('name_en')->get(['id', 'name_en', 'name_ar']),
            'kinds' => FieldWork::NODE_KINDS,
            'can_manage' => $actor instanceof User && $access->allows($actor, 'services.technical.edit'),
        ]);
    }

    public function store(Request $request, AccessGate $access, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'parent_id' => ['nullable', 'integer', 'exists:network_nodes,id'],
            'kind' => ['required', 'in:'.implode(',', FieldWork::NODE_KINDS)],
            'name' => ['required', 'string', 'max:160'],
            'area_id' => ['nullable', 'integer', 'exists:areas,id'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $node = NetworkNode::query()->create($data);
        $actor = $access->effective($request);
        $audit->record('network_node.created', $actor instanceof User ? $actor : null, ['node_id' => $node->id, 'kind' => $node->kind], $request);

        return back()->with('status', __('ui.nodes.saved'));
    }
}
