<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\StockItem;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Approvals\ApprovalDesk;
use App\Services\Audit\AuditLogger;
use App\Services\Inventory\StockLedger;
use App\Support\Access\AccessGate;
use App\Support\CsvCell;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PurchasingDesk
{
    /** @var array<string, list<string>> */
    public const ALLOWED = [
        'draft' => ['pending_approval', 'cancelled'],
        'pending_approval' => ['approved', 'cancelled'],
        'approved' => ['sent', 'cancelled'],
        'sent' => ['partially_received', 'received', 'cancelled'],
        'partially_received' => ['received'],
        'received' => [],
        'cancelled' => [],
    ];

    public function __construct(
        private AuditLogger $audit,
        private AccessGate $access,
        private StockLedger $stock,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function open(User $actor, array $input, Request $request): PurchaseOrder
    {
        $total = '0.00';
        $lines = $input['lines'] ?? [];

        foreach ($lines as $line) {
            $total = bcadd($total, bcmul((string) $line['qty'], (string) $line['unit_cost'], 2), 2);
        }

        $po = PurchaseOrder::query()->create([
            'supplier_id' => $input['supplier_id'] ?? Supplier::query()->value('id'),
            'status' => 'draft',
            'erp_ref' => $input['erp_ref'] ?? null,
            'currency' => 'USD',
            'total' => $total,
            'owner_id' => $actor->id,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        foreach ($lines as $line) {
            PurchaseOrderLine::query()->create([
                'purchase_order_id' => $po->id,
                'item_id' => $line['item_id'],
                'qty' => $line['qty'],
                'unit_cost' => $line['unit_cost'],
                'expected_on' => $line['expected_on'] ?? null,
            ]);
        }

        $this->audit->record('purchasing.created', $actor, [
            'purchase_order_id' => $po->id,
            'total' => (string) $po->total,
        ], $request);

        return $po->fresh() ?? $po;
    }

    public function draftShortage(User $actor, StockItem $item, string $qty, Order $order, Request $request): PurchaseOrder
    {
        return $this->open($actor, [
            'erp_ref' => 'SHORT-'.$order->number,
            'lines' => [[
                'item_id' => $item->id,
                'qty' => $qty,
                'unit_cost' => $item->unit_cost_usd ?? '0.00',
            ]],
        ], $request);
    }

    public function submit(PurchaseOrder $po, User $actor, Request $request): PurchaseOrder
    {
        $this->assert($po->status, 'pending_approval');
        $po->status = 'pending_approval';
        $po->save();
        $approval = app(ApprovalDesk::class)->open(
            $actor,
            'purchase_order',
            'purchase_order',
            $po->id,
            ['amount' => (string) $po->total],
            (string) $po->number,
            $request,
        );
        $po->refresh();
        $po->approval_id = $approval->id;

        if ($approval->status === 'approved' && $po->status !== 'approved') {
            $po->status = 'approved';
        }

        $po->save();
        $this->audit->record('purchasing.submitted', $actor, [
            'purchase_order_id' => $po->id,
            'total' => (string) $po->total,
            'approval' => $approval->status,
        ], $request);

        return $po->fresh() ?? $po;
    }

    public function markApproved(PurchaseOrder $po, User $actor): void
    {
        if (! in_array($po->status, ['draft', 'pending_approval'], true)) {
            return;
        }

        $po->status = 'approved';
        $po->updated_by = $actor->id;
        $po->save();
    }

    public function send(PurchaseOrder $po, User $actor, Request $request): void
    {
        $this->assert($po->status, 'sent');
        $po->status = 'sent';
        $po->updated_by = $actor->id;
        $po->save();
        $this->audit->record('purchasing.sent', $actor, ['purchase_order_id' => $po->id], $request);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function receive(PurchaseOrder $po, array $input, User $actor, Request $request): void
    {
        if (! in_array($po->status, ['sent', 'partially_received'], true)) {
            throw ValidationException::withMessages(['status' => __('ui.purchasing.illegal')]);
        }

        $line = $po->lines()->whereKey((int) $input['line_id'])->firstOrFail();
        $qty = bcadd((string) $input['quantity'], '0', 3);
        $remaining = bcsub((string) $line->qty, (string) $line->received_qty, 3);

        if (bccomp($qty, '0', 3) !== 1 || bccomp($qty, $remaining, 3) === 1) {
            throw ValidationException::withMessages(['quantity' => __('ui.purchasing.quantity')]);
        }

        $location = StockLocation::query()->findOrFail((int) $input['location_id']);
        $before = StockMovement::query()->max('id');
        $this->stock->receive($line->item, $location, $qty, [], $actor, $request);
        $line->received_qty = bcadd((string) $line->received_qty, $qty, 3);
        $line->save();
        $open = $po->lines()->get()->contains(fn (PurchaseOrderLine $row) => bccomp((string) $row->received_qty, (string) $row->qty, 3) === -1);
        $po->status = $open ? 'partially_received' : 'received';
        $po->save();
        $this->audit->record('purchasing.received', $actor, [
            'purchase_order_id' => $po->id,
            'line_id' => $line->id,
            'quantity' => $qty,
            'movement_after' => $before,
        ], $request);
    }

    public function export(User $actor, Request $request): string
    {
        $seeCost = $this->access->allows($actor, 'billing.view_cost');
        $handle = fopen('php://temp', 'w+');
        fputcsv($handle, $seeCost
            ? ['id', 'sku', 'kind', 'quantity', 'unit_cost_usd', 'created_at']
            : ['id', 'sku', 'kind', 'quantity', 'created_at']);
        $count = 0;

        StockMovement::query()->with('item')->orderBy('id')->each(function (StockMovement $row) use ($handle, $seeCost, &$count): void {
            $when = $row->created_at?->timezone((string) config('app.timezone'))->format('d/m/Y H:i');
            fputcsv($handle, CsvCell::row($seeCost
                ? [$row->id, $row->item?->sku, $row->kind, $row->quantity, $row->unit_cost_usd, $when]
                : [$row->id, $row->item?->sku, $row->kind, $row->quantity, $when]));
            $count++;
        });
        rewind($handle);
        $body = stream_get_contents($handle) ?: '';
        fclose($handle);
        $this->audit->record('purchasing.exported', $actor, ['rows' => $count], $request);

        return $body;
    }

    private function assert(string $from, string $to): void
    {
        if (! in_array($to, self::ALLOWED[$from] ?? [], true)) {
            throw ValidationException::withMessages(['status' => __('ui.purchasing.illegal')]);
        }
    }
}
