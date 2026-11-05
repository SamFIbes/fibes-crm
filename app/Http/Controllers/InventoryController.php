<?php

namespace App\Http\Controllers;

use App\Jobs\PushErpNextMaterialRequest;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Job;
use App\Models\NetworkNode;
use App\Models\StockCount;
use App\Models\StockItem;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\StockRequest;
use App\Models\StockSerial;
use App\Models\User;
use App\Services\Integrations\ErpNext\ErpNextMap;
use App\Services\Integrations\IntegrationSettings;
use App\Services\Inventory\StockLedger;
use App\Support\Access\AccessGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class InventoryController extends Controller
{
    public function index(Request $request, StockLedger $ledger, AccessGate $access, IntegrationSettings $integrations): Response
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
            'movements' => StockMovement::query()->with(['customer:id,name_en', 'node:id,name', 'job:id,number', 'expense:id,number,title'])->latest('id')->limit(12)->get()->map(fn (StockMovement $row) => [
                'id' => $row->id,
                'kind' => $row->kind,
                'quantity' => (string) $row->quantity,
                'unit_cost_usd' => (string) $row->unit_cost_usd,
                'reason' => $row->reason,
                'item' => StockItem::query()->whereKey($row->item_id)->value('name_en'),
                'customer' => $row->customer?->name_en,
                'node' => $row->node?->name,
                'job' => $row->job?->number,
                'expense' => $row->expense?->number,
            ]),
            'jobs' => Job::query()->whereNotIn('status', ['done', 'failed'])->latest('id')->limit(20)->get(['id', 'number']),
            'count' => $this->latestCount($locations->pluck('id')->all()),
            'can_move' => $access->allows($actor, 'inventory.move'),
            'can_adjust' => $access->allows($actor, 'inventory.adjust'),
            'can_reserve' => $access->allows($actor, 'inventory.reserve'),
            'erp' => $integrations->runtime('erpnext'),
            'customers' => Customer::query()->orderByDesc('id')->limit(40)->get(['id', 'name_en', 'name_ar', 'account_no']),
            'nodes' => NetworkNode::query()->orderBy('name')->limit(80)->get(['id', 'name', 'kind']),
            'expenses' => Expense::query()->latest('id')->limit(40)->get(['id', 'number', 'title']),
            'requests' => StockRequest::query()->with('item:id,name_en,name_ar')->latest('id')->limit(8)->get()->map(fn (StockRequest $row) => [
                'id' => $row->id,
                'number' => $row->number,
                'status' => $row->status,
                'purpose' => $row->purpose,
                'quantity' => (string) $row->quantity,
                'item' => $row->item?->name_en,
                'erp_name' => $row->erp_name,
                'last_error' => $row->last_error,
            ]),
        ]);
    }

    public function issue(Request $request, StockLedger $ledger, AccessGate $access): RedirectResponse
    {
        $data = $this->links($request);
        $item = StockItem::query()->findOrFail($data['item_id']);
        $ledger->issueTo(
            $item,
            StockLocation::query()->findOrFail($data['location_id']),
            (string) ($data['quantity'] ?? '1'),
            $this->serial($item, $data['serial'] ?? null),
            $this->actor($request, $access),
            $request,
            $data['customer_id'],
            $data['network_node_id'],
            $data['job_id'],
            $this->expenseId($data, $this->actor($request, $access)),
        );

        return back()->with('status', __('ui.inventory.issued_linked'));
    }

    public function requestFromErp(Request $request, AccessGate $access, IntegrationSettings $integrations, ErpNextMap $map): RedirectResponse
    {
        if (! $integrations->runtime('erpnext')['live']) {
            return back()->withErrors(['erp' => __('ui.inventory.erp_off')]);
        }

        $data = $this->links($request, true);
        $actor = $this->actor($request, $access);
        $expenseId = $this->expenseId($data, $actor);
        $row = StockRequest::query()->create([
            'status' => 'queued',
            'purpose' => $data['purpose'] ?: $integrations->purpose(),
            'item_id' => $data['item_id'],
            'location_id' => $data['location_id'],
            'quantity' => $data['quantity'] ?? '1',
            'warehouse' => $integrations->warehouseFor($data['location_id']),
            'required_by' => $data['required_by'],
            'customer_id' => $data['customer_id'],
            'network_node_id' => $data['network_node_id'],
            'job_id' => $data['job_id'],
            'expense_id' => $expenseId,
            'requested_by' => $actor->id,
        ]);
        $row->refresh();
        $row->remarks = $map->remarks($row);
        $row->save();

        try {
            PushErpNextMaterialRequest::dispatch($row->id);
        } catch (Throwable) {
            return back()->withErrors(['erp' => __('ui.inventory.erp_failed')]);
        }

        return back()->with('status', __('ui.inventory.erp_queued'));
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

    /**
     * @return array<string, mixed>
     */
    private function links(Request $request, bool $erp = false): array
    {
        $rules = [
            'item_id' => ['required', 'integer', 'exists:stock_items,id'],
            'location_id' => ['required', 'integer', 'exists:stock_locations,id'],
            'quantity' => ['nullable', 'numeric'],
            'serial' => ['nullable', 'string', 'max:80'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'network_node_id' => ['nullable', 'integer', 'exists:network_nodes,id'],
            'job_id' => ['nullable', 'integer', 'exists:field_jobs,id'],
            'expense_id' => ['nullable', 'integer', 'exists:expenses,id'],
            'expense_title' => ['nullable', 'string', 'max:160'],
            'expense_amount' => ['nullable', 'numeric', 'min:0'],
        ];

        if ($erp) {
            $rules['purpose'] = ['nullable', 'in:Purchase,Material Transfer,Material Issue'];
            $rules['required_by'] = ['nullable', 'date'];
        }

        $data = $request->validate($rules);

        return [
            'item_id' => (int) $data['item_id'],
            'location_id' => (int) $data['location_id'],
            'quantity' => isset($data['quantity']) ? (string) $data['quantity'] : null,
            'serial' => $data['serial'] ?? null,
            'customer_id' => isset($data['customer_id']) ? (int) $data['customer_id'] : null,
            'network_node_id' => isset($data['network_node_id']) ? (int) $data['network_node_id'] : null,
            'job_id' => isset($data['job_id']) ? (int) $data['job_id'] : null,
            'expense_id' => isset($data['expense_id']) ? (int) $data['expense_id'] : null,
            'expense_title' => trim((string) ($data['expense_title'] ?? '')),
            'expense_amount' => isset($data['expense_amount']) ? (string) $data['expense_amount'] : '0',
            'purpose' => $data['purpose'] ?? null,
            'required_by' => $data['required_by'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function expenseId(array $data, User $actor): ?int
    {
        if (! empty($data['expense_id'])) {
            return (int) $data['expense_id'];
        }

        if (($data['expense_title'] ?? '') === '') {
            return null;
        }

        $expense = Expense::query()->create([
            'title' => $data['expense_title'],
            'amount_usd' => $data['expense_amount'] ?? '0',
            'status' => 'draft',
            'customer_id' => $data['customer_id'],
            'network_node_id' => $data['network_node_id'],
            'job_id' => $data['job_id'],
            'actor_id' => $actor->id,
        ]);

        return $expense->id;
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
