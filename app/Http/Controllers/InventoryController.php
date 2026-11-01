<?php

namespace App\Http\Controllers;

use App\Models\Job;
use App\Models\StockCount;
use App\Models\StockItem;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\StockSerial;
use App\Models\User;
use App\Services\Inventory\StockLedger;
use App\Support\Access\AccessGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InventoryController extends Controller
{
    public function index(Request $request, StockLedger $ledger, AccessGate $access): Response
    {
        $actor = $this->actor($request, $access);
        $locations = $ledger->locations($actor)->orderBy('name')->get();
        $items = StockItem::query()->orderBy('name_en')->get()->map(function (StockItem $item) use ($ledger, $locations) {
            $onHand = '0.000';

            foreach ($locations as $location) {
                $onHand = bcadd($onHand, $ledger->onHand($item, $location->id), 3);
            }

            return [
                'id' => $item->id,
                'sku' => $item->sku,
                'name_en' => $item->name_en,
                'name_ar' => $item->name_ar,
                'unit' => $item->unit,
                'serialized' => $item->serialized,
                'reorder_level' => (string) $item->reorder_level,
                'unit_cost_usd' => (string) $item->unit_cost_usd,
                'on_hand' => $onHand,
                'low' => bccomp($onHand, (string) $item->reorder_level, 3) <= 0,
            ];
        });

        return Inertia::render('Inventory/Index', [
            'items' => $items,
            'locations' => $locations->map(fn (StockLocation $location) => [
                'id' => $location->id,
                'name' => $location->name,
                'kind' => $location->kind,
            ]),
            'movements' => StockMovement::query()->latest('id')->limit(12)->get()->map(fn (StockMovement $row) => [
                'id' => $row->id,
                'kind' => $row->kind,
                'quantity' => (string) $row->quantity,
                'unit_cost_usd' => (string) $row->unit_cost_usd,
                'reason' => $row->reason,
                'item' => StockItem::query()->whereKey($row->item_id)->value('name_en'),
            ]),
            'jobs' => Job::query()->whereNotIn('status', ['done', 'failed'])->latest('id')->limit(20)->get(['id', 'number']),
            'count' => $this->latestCount($locations->pluck('id')->all()),
            'can_move' => $access->allows($actor, 'inventory.move'),
            'can_adjust' => $access->allows($actor, 'inventory.adjust'),
            'can_reserve' => $access->allows($actor, 'inventory.reserve'),
        ]);
    }

    public function receive(Request $request, StockLedger $ledger, AccessGate $access): RedirectResponse
    {
        $data = $this->validated($request);
        $ledger->receive(
            StockItem::query()->findOrFail($data['item_id']),
            StockLocation::query()->findOrFail($data['location_id']),
            (string) ($data['quantity'] ?? '0'),
            $data['serials'],
            $this->actor($request, $access),
            $request,
        );

        return back()->with('status', __('ui.inventory.received'));
    }

    public function transfer(Request $request, StockLedger $ledger, AccessGate $access): RedirectResponse
    {
        $data = $request->validate([
            'item_id' => ['required', 'integer', 'exists:stock_items,id'],
            'from_location_id' => ['required', 'integer', 'exists:stock_locations,id'],
            'to_location_id' => ['required', 'integer', 'exists:stock_locations,id', 'different:from_location_id'],
            'quantity' => ['nullable', 'numeric'],
            'serial' => ['nullable', 'string', 'max:80'],
        ]);
        $item = StockItem::query()->findOrFail($data['item_id']);
        $serial = $this->serial($item, $data['serial'] ?? null);
        $ledger->transfer($item, StockLocation::query()->findOrFail($data['from_location_id']), StockLocation::query()->findOrFail($data['to_location_id']), (string) ($data['quantity'] ?? '1'), $serial, $this->actor($request, $access), $request);

        return back()->with('status', __('ui.inventory.transferred'));
    }

    public function putBack(Request $request, StockLedger $ledger, AccessGate $access): RedirectResponse
    {
        $data = $request->validate([
            'item_id' => ['required', 'integer', 'exists:stock_items,id'],
            'location_id' => ['required', 'integer', 'exists:stock_locations,id'],
            'quantity' => ['nullable', 'numeric'],
            'serial' => ['nullable', 'string', 'max:80'],
        ]);
        $item = StockItem::query()->findOrFail($data['item_id']);
        $ledger->putBack($item, StockLocation::query()->findOrFail($data['location_id']), (string) ($data['quantity'] ?? '1'), $this->serial($item, $data['serial'] ?? null), $this->actor($request, $access), $request);

        return back()->with('status', __('ui.inventory.returned_stock'));
    }

    public function writeOff(Request $request, StockLedger $ledger, AccessGate $access): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        abort_unless($access->allows($actor, 'inventory.adjust'), 403);
        $data = $request->validate([
            'item_id' => ['required', 'integer', 'exists:stock_items,id'],
            'location_id' => ['required', 'integer', 'exists:stock_locations,id'],
            'quantity' => ['nullable', 'numeric'],
            'serial' => ['nullable', 'string', 'max:80'],
            'reason' => ['required', 'string', 'max:200'],
            'disposition' => ['required', 'in:faulty,returned_vendor'],
        ]);
        $item = StockItem::query()->findOrFail($data['item_id']);
        $ledger->writeOff($item, StockLocation::query()->findOrFail($data['location_id']), (string) ($data['quantity'] ?? '1'), $this->serial($item, $data['serial'] ?? null), $data['reason'], $data['disposition'], $actor, $request);

        return back()->with('status', __('ui.inventory.written_off'));
    }

    public function reserve(Request $request, StockLedger $ledger, AccessGate $access): RedirectResponse
    {
        $data = $request->validate([
            'job_id' => ['required', 'integer', 'exists:field_jobs,id'],
            'item_id' => ['required', 'integer', 'exists:stock_items,id'],
            'location_id' => ['required', 'integer', 'exists:stock_locations,id'],
            'quantity' => ['required', 'numeric'],
        ]);
        $ledger->reserve(Job::query()->findOrFail($data['job_id']), StockItem::query()->findOrFail($data['item_id']), StockLocation::query()->findOrFail($data['location_id']), (string) $data['quantity'], $this->actor($request, $access), $request);

        return back()->with('status', __('ui.inventory.reserved'));
    }

    public function count(Request $request, StockLedger $ledger, AccessGate $access): RedirectResponse
    {
        $data = $request->validate([
            'location_id' => ['required', 'integer', 'exists:stock_locations,id'],
            'note' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'integer', 'exists:stock_items,id'],
            'lines.*.counted_qty' => ['required', 'numeric'],
        ]);
        $ledger->count(StockLocation::query()->findOrFail($data['location_id']), $data['lines'], $data['note'] ?? null, $this->actor($request, $access), $request);

        return back()->with('status', __('ui.inventory.counted'));
    }

    /**
     * @return array{item_id: int, location_id: int, quantity: ?string, serials: list<array{serial: string, mac: ?string}>}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'item_id' => ['required', 'integer', 'exists:stock_items,id'],
            'location_id' => ['required', 'integer', 'exists:stock_locations,id'],
            'quantity' => ['nullable', 'numeric'],
            'serials' => ['array'],
            'serials.*.serial' => ['required', 'string', 'max:80'],
            'serials.*.mac' => ['nullable', 'string', 'max:40'],
        ]);

        return [
            'item_id' => (int) $data['item_id'],
            'location_id' => (int) $data['location_id'],
            'quantity' => isset($data['quantity']) ? (string) $data['quantity'] : null,
            'serials' => array_map(fn (array $row) => ['serial' => $row['serial'], 'mac' => $row['mac'] ?? null], $data['serials'] ?? []),
        ];
    }

    /**
     * @param  list<int>  $locationIds
     * @return array<string, mixed>|null
     */
    private function latestCount(array $locationIds): ?array
    {
        $count = StockCount::query()->with('lines.item')->whereIn('location_id', $locationIds)->latest('id')->first();

        if (! $count instanceof StockCount) {
            return null;
        }

        return [
            'id' => $count->id,
            'note' => $count->note,
            'lines' => $count->lines->map(fn ($line) => [
                'item' => $line->item?->name_en,
                'expected' => (string) $line->expected_qty,
                'counted' => (string) $line->counted_qty,
                'variance' => bcsub((string) $line->counted_qty, (string) $line->expected_qty, 3),
            ])->all(),
        ];
    }

    private function serial(StockItem $item, ?string $serial): ?StockSerial
    {
        if (! $item->serialized) {
            return null;
        }

        return StockSerial::query()->where('item_id', $item->id)->where('serial', (string) $serial)->first();
    }

    private function actor(Request $request, AccessGate $access): User
    {
        $actor = $access->effective($request);
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
