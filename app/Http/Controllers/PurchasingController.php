<?php

namespace App\Http\Controllers;

use App\Models\PurchaseOrder;
use App\Services\Orders\PurchasingDesk;
use App\Support\Access\AccessGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PurchasingController extends Controller
{
    public function store(Request $request, AccessGate $access, PurchasingDesk $desk): RedirectResponse
    {
        $actor = $access->effective($request);
        abort_unless($actor !== null, 403);
        $data = $request->validate([
            'supplier_id' => ['nullable', 'integer'],
            'erp_ref' => ['nullable', 'string', 'max:40'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'integer', 'exists:stock_items,id'],
            'lines.*.qty' => ['required', 'numeric', 'min:0.001'],
            'lines.*.unit_cost' => ['required', 'numeric', 'min:0'],
            'lines.*.expected_on' => ['nullable', 'date'],
        ]);
        $po = $desk->open($actor, $data, $request);

        return back()->with('status', __('ui.purchasing.saved', ['number' => $po->number]));
    }

    public function submit(Request $request, PurchaseOrder $purchaseOrder, AccessGate $access, PurchasingDesk $desk): RedirectResponse
    {
        $actor = $access->effective($request);
        abort_unless($actor !== null, 403);
        $desk->submit($purchaseOrder, $actor, $request);

        return back()->with('status', __('ui.purchasing.pending'));
    }

    public function send(Request $request, PurchaseOrder $purchaseOrder, AccessGate $access, PurchasingDesk $desk): RedirectResponse
    {
        $actor = $access->effective($request);
        abort_unless($actor !== null, 403);
        $desk->send($purchaseOrder, $actor, $request);

        return back()->with('status', __('ui.purchasing.saved', ['number' => $purchaseOrder->number]));
    }

    public function receive(Request $request, PurchaseOrder $purchaseOrder, AccessGate $access, PurchasingDesk $desk): RedirectResponse
    {
        $actor = $access->effective($request);
        abort_unless($actor !== null, 403);
        $data = $request->validate([
            'line_id' => ['required', 'integer'],
            'location_id' => ['required', 'integer', 'exists:stock_locations,id'],
            'quantity' => ['required', 'numeric', 'min:0.001'],
        ]);
        $desk->receive($purchaseOrder, $data, $actor, $request);

        return back()->with('status', __('ui.purchasing.received'));
    }
}
