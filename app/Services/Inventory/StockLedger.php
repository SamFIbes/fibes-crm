<?php

namespace App\Services\Inventory;

use App\Models\Job;
use App\Models\StockCount;
use App\Models\StockItem;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\StockSerial;
use App\Models\User;
use App\Notifications\WorkspaceNotification;
use App\Services\Approvals\ApprovalDesk;
use App\Services\Audit\AuditLogger;
use App\Support\Access\AccessGate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class StockLedger
{
    public function __construct(
        private AuditLogger $audit,
        private AccessGate $access,
    ) {}

    public function seed(): void
    {
        StockLocation::query()->updateOrCreate(['name' => 'Main warehouse'], ['kind' => 'warehouse', 'is_active' => true]);
        StockLocation::query()->updateOrCreate(['name' => 'Head office'], ['kind' => 'office', 'is_active' => true]);

        $items = [
            ['ont', 'ONT', 'جهاز ألياف', 'piece', true, '5.000', '45.00'],
            ['router', 'Router', 'موجّه', 'piece', true, '5.000', '35.00'],
            ['cpe', 'CPE', 'جهاز لاسلكي', 'piece', true, '5.000', '55.00'],
            ['antenna', 'Antenna', 'هوائي', 'piece', true, '4.000', '25.00'],
            ['fiber', 'Fiber cable', 'كابل ألياف', 'meter', false, '200.000', '0.40'],
            ['connector', 'Connector', 'موصل', 'piece', false, '50.000', '0.80'],
            ['splitter', 'Splitter', 'مفرّع', 'piece', true, '10.000', '12.00'],
        ];

        foreach ($items as [$sku, $en, $ar, $unit, $serialized, $reorder, $cost]) {
            StockItem::query()->updateOrCreate(['sku' => $sku], [
                'name_en' => $en,
                'name_ar' => $ar,
                'unit' => $unit,
                'serialized' => $serialized,
                'reorder_level' => $reorder,
                'unit_cost_usd' => $cost,
                'low_stock_alerted' => true,
            ]);
        }

        User::query()->whereIn('role', ['field_technician', 'installer'])->each(function (User $user): void {
            $this->ensureVan($user);
        });
    }

    public function locations(User $viewer): Builder
    {
        $query = StockLocation::query()->where('is_active', true);

        if ($this->access->scopeFor($viewer, 'inventory.view') === 'own' && ! $this->access->allows($viewer, 'inventory.move')) {
            return $query->where('user_id', $viewer->id);
        }

        if ($this->access->allows($viewer, 'inventory.view') || $this->access->allows($viewer, 'inventory.move')) {
            return $query;
        }

        return $query->whereRaw('0 = 1');
    }

    public function ensureVan(User $user): StockLocation
    {
        return StockLocation::query()->firstOrCreate(
            ['kind' => 'van', 'user_id' => $user->id],
            ['name' => $user->name.' van', 'is_active' => true],
        );
    }

    /**
     * @param  list<array{serial: string, mac?: string|null}>  $serials
     */
    public function receive(StockItem $item, StockLocation $location, string $quantity, array $serials, User $actor, ?Request $request): void
    {
        $quantity = $this->normalize($item, $quantity, $serials);

        DB::transaction(function () use ($item, $location, $quantity, $serials, $actor, $request): void {
            $created = [];

            foreach ($serials as $row) {
                if (StockSerial::query()->where('item_id', $item->id)->where('serial', $row['serial'])->exists()) {
                    throw ValidationException::withMessages(['serial' => __('ui.inventory.serial_taken')]);
                }

                $created[] = StockSerial::query()->create([
                    'item_id' => $item->id,
                    'serial' => $row['serial'],
                    'mac' => $row['mac'] ?? null,
                    'status' => 'in_stock',
                    'location_id' => $location->id,
                ]);
            }

            if ($created === []) {
                $this->move($item, $quantity, 'receive', null, $location->id, null, null, null, $actor, null, null);
            }

            foreach ($created as $serial) {
                $this->move($item, '1.000', 'receive', null, $location->id, $serial->id, null, null, $actor, null, null);
            }

            $this->audit->record('stock.received', $actor, [
                'item_id' => $item->id,
                'location_id' => $location->id,
                'quantity' => $quantity,
            ], $request);
            $this->watchLevel($item);
        });
    }

    public function transfer(StockItem $item, StockLocation $from, StockLocation $to, string $quantity, ?StockSerial $serial, User $actor, ?Request $request): void
    {
        $quantity = $this->normalize($item, $quantity, $serial ? [['serial' => $serial->serial]] : []);
        $this->assertAvailable($item, $from, $quantity, $serial);

        DB::transaction(function () use ($item, $from, $to, $quantity, $serial, $actor, $request): void {
            if ($serial instanceof StockSerial) {
                $serial->location_id = $to->id;
                $serial->save();
            }

            $this->move($item, $quantity, 'transfer', $from->id, $to->id, $serial?->id, null, null, $actor, null, null);
            $this->audit->record('stock.transferred', $actor, [
                'item_id' => $item->id,
                'from' => $from->id,
                'to' => $to->id,
                'quantity' => $quantity,
            ], $request);
            $this->watchLevel($item);
        });
    }

    public function issue(Job $job, StockItem $item, StockLocation $from, string $quantity, ?StockSerial $serial, User $actor, ?Request $request, ?string $idempotency = null): void
    {
        if ($idempotency && DB::table('job_sync_keys')->where('idempotency_key', $idempotency)->exists()) {
            return;
        }

        $quantity = $this->normalize($item, $quantity, $serial ? [['serial' => $serial->serial]] : []);
        $this->assertAvailable($item, $from, $quantity, $serial);

        DB::transaction(function () use ($job, $item, $from, $quantity, $serial, $actor, $request, $idempotency): void {
            if ($serial instanceof StockSerial) {
                $serial->status = 'reserved';
                $serial->job_id = $job->id;
                $serial->save();
            }

            $this->move($item, $quantity, 'issue', $from->id, null, $serial?->id, $job->id, null, $actor, null, null, $job->customer_id ? (int) $job->customer_id : null);
            StockReservation::query()
                ->where('job_id', $job->id)
                ->where('item_id', $item->id)
                ->where('status', 'held')
                ->limit(1)
                ->update(['status' => 'issued']);

            if ($idempotency) {
                DB::table('job_sync_keys')->insert([
                    'idempotency_key' => $idempotency,
                    'job_id' => $job->id,
                    'action' => 'issue',
                    'created_at' => now(),
                ]);
            }

            $note = trim((string) $job->materials);
            $line = $item->name_en.' '.$quantity.($serial ? ' '.$serial->serial : '');
            $job->materials = $note === '' ? $line : $note."\n".$line;
            $job->save();
            $this->audit->record('stock.issued', $actor, [
                'item_id' => $item->id,
                'job_id' => $job->id,
                'customer_id' => $job->customer_id,
                'quantity' => $quantity,
                'serial' => $serial?->serial,
            ], $request);
            $this->watchLevel($item);
        });
    }

    public function issueTo(
        StockItem $item,
        StockLocation $from,
        string $quantity,
        ?StockSerial $serial,
        User $actor,
        ?Request $request,
        ?int $customerId,
        ?int $networkNodeId,
        ?int $jobId,
        ?int $expenseId,
    ): void {
        $quantity = $this->normalize($item, $quantity, $serial ? [['serial' => $serial->serial]] : []);
        $this->assertAvailable($item, $from, $quantity, $serial);

        if ($customerId === null && $jobId !== null) {
            $linked = Job::query()->whereKey($jobId)->value('customer_id');
            $customerId = $linked !== null ? (int) $linked : null;
        }

        DB::transaction(function () use ($item, $from, $quantity, $serial, $actor, $request, $customerId, $networkNodeId, $jobId, $expenseId): void {
            if ($serial instanceof StockSerial) {
                $serial->status = 'reserved';

                if ($jobId !== null) {
                    $serial->job_id = $jobId;
                }

                $serial->save();
            }

            $this->move($item, $quantity, 'issue', $from->id, null, $serial?->id, $jobId, null, $actor, null, null, $customerId, $networkNodeId, $expenseId);

            if ($jobId !== null) {
                $job = Job::query()->find($jobId);

                if ($job instanceof Job) {
                    $note = trim((string) $job->materials);
                    $line = $item->name_en.' '.$quantity.($serial ? ' '.$serial->serial : '');
                    $job->materials = $note === '' ? $line : $note."\n".$line;
                    $job->save();
                }
            }

            $this->audit->record('stock.issued', $actor, [
                'item_id' => $item->id,
                'job_id' => $jobId,
                'customer_id' => $customerId,
                'network_node_id' => $networkNodeId,
                'expense_id' => $expenseId,
                'quantity' => $quantity,
                'serial' => $serial?->serial,
            ], $request);
            $this->watchLevel($item);
        });
    }

    public function install(Job $job, StockSerial $serial, int $serviceId, User $actor, ?Request $request): void
    {
        if (! in_array($serial->status, ['in_stock', 'reserved'], true)) {
            throw ValidationException::withMessages(['serial' => __('ui.inventory.serial_unavailable')]);
        }

        DB::transaction(function () use ($job, $serial, $serviceId, $actor, $request): void {
            $from = $serial->location_id;
            $serial->fill([
                'status' => 'installed',
                'location_id' => null,
                'customer_service_id' => $serviceId,
                'job_id' => $job->id,
            ])->save();
            $item = $serial->item ?? StockItem::query()->findOrFail($serial->item_id);
            $this->move($item, '1.000', 'install', $from, null, $serial->id, $job->id, $serviceId, $actor, null, null);
            $this->audit->record('stock.installed', $actor, [
                'serial' => $serial->serial,
                'customer_service_id' => $serviceId,
                'job_id' => $job->id,
                'customer_id' => $job->customer_id,
            ], $request);
        });
    }

    public function putBack(StockItem $item, StockLocation $to, string $quantity, ?StockSerial $serial, User $actor, ?Request $request): void
    {
        $quantity = $serial instanceof StockSerial
            ? '1.000'
            : $this->quantityOf($item, $quantity);

        DB::transaction(function () use ($item, $to, $quantity, $serial, $actor, $request): void {
            if ($serial instanceof StockSerial) {
                $serial->fill([
                    'status' => 'in_stock',
                    'location_id' => $to->id,
                    'customer_service_id' => null,
                    'job_id' => null,
                ])->save();
            }

            $this->move($item, $quantity, 'return', null, $to->id, $serial?->id, null, null, $actor, null, null);
            $this->audit->record('stock.returned', $actor, [
                'item_id' => $item->id,
                'location_id' => $to->id,
                'quantity' => $quantity,
                'serial' => $serial?->serial,
            ], $request);
            $this->watchLevel($item);
        });
    }

    public function writeOff(StockItem $item, StockLocation $from, string $quantity, ?StockSerial $serial, string $reason, string $disposition, User $actor, ?Request $request): void
    {
        $quantity = $this->normalize($item, $quantity, $serial ? [['serial' => $serial->serial]] : []);
        $this->assertAvailable($item, $from, $quantity, $serial);

        DB::transaction(function () use ($item, $from, $quantity, $serial, $reason, $disposition, $actor, $request): void {
            if ($serial instanceof StockSerial) {
                $serial->status = $disposition === 'returned_vendor' ? 'returned_vendor' : 'faulty';
                $serial->location_id = null;
                $serial->save();
            }

            $this->move($item, $quantity, 'write_off', $from->id, null, $serial?->id, null, null, $actor, $actor->id, $reason);
            $this->audit->record('stock.written_off', $actor, [
                'item_id' => $item->id,
                'quantity' => $quantity,
                'reason' => $reason,
            ], $request);
            $this->watchLevel($item);
        });
    }

    public function reserve(Job $job, StockItem $item, StockLocation $location, string $quantity, User $actor, ?Request $request): void
    {
        $quantity = $this->quantityOf($item, $quantity);
        $available = bcsub($this->onHand($item, $location->id), $this->held($item, $location->id), 3);

        if (bccomp($available, $quantity, 3) < 0) {
            throw ValidationException::withMessages(['quantity' => __('ui.inventory.short')]);
        }

        StockReservation::query()->create([
            'job_id' => $job->id,
            'item_id' => $item->id,
            'location_id' => $location->id,
            'quantity' => $quantity,
            'status' => 'held',
        ]);
        $this->audit->record('stock.reserved', $actor, [
            'item_id' => $item->id,
            'job_id' => $job->id,
            'quantity' => $quantity,
        ], $request);
    }

    /**
     * @param  list<array{item_id: int, counted_qty: string}>  $lines
     */
    public function count(StockLocation $location, array $lines, ?string $note, User $actor, ?Request $request): StockCount
    {
        return DB::transaction(function () use ($location, $lines, $note, $actor, $request) {
            $count = StockCount::query()->create([
                'location_id' => $location->id,
                'user_id' => $actor->id,
                'note' => $note,
            ]);

            foreach ($lines as $line) {
                $item = StockItem::query()->findOrFail($line['item_id']);
                $count->lines()->create([
                    'item_id' => $item->id,
                    'expected_qty' => $this->onHand($item, $location->id),
                    'counted_qty' => $this->quantityOf($item, (string) $line['counted_qty'], true),
                ]);
            }

            $this->audit->record('stock.counted', $actor, ['count_id' => $count->id, 'location_id' => $location->id], $request);
            $units = '0.000';
            $value = '0.00';

            foreach ($count->lines()->with('item')->get() as $line) {
                $gap = bcsub((string) $line->counted_qty, (string) $line->expected_qty, 3);
                $gap = ltrim($gap, '-');

                if (bccomp($gap, $units, 3) > 0) {
                    $units = $gap;
                }

                $cost = (string) ($line->item?->unit_cost_usd ?? '0');
                $value = bcadd($value, bcmul($gap, $cost, 2), 2);
            }

            if (bccomp($units, '2', 3) > 0) {
                app(ApprovalDesk::class)->open($actor, 'stock_variance', 'stock_count', $count->id, [
                    'units' => $units,
                    'amount' => $value,
                ], __('ui.approvals.stock_summary', ['units' => $units]), $request);
            }

            return $count;
        });
    }

    public function onHand(StockItem $item, int $locationId): string
    {
        if ($item->serialized) {
            $count = StockSerial::query()
                ->where('item_id', $item->id)
                ->where('location_id', $locationId)
                ->whereIn('status', ['in_stock', 'reserved'])
                ->count();

            return number_format($count, 3, '.', '');
        }

        $sum = '0.000';

        StockMovement::query()->where('item_id', $item->id)->orderBy('id')->each(function (StockMovement $row) use (&$sum, $locationId): void {
            $qty = (string) $row->quantity;

            if ((int) $row->to_location_id === $locationId && in_array($row->kind, ['receive', 'transfer', 'return'], true)) {
                $sum = bcadd($sum, $qty, 3);
            }

            if ((int) $row->from_location_id === $locationId && in_array($row->kind, ['transfer', 'issue', 'install', 'write_off'], true)) {
                $sum = bcsub($sum, $qty, 3);
            }
        });

        return $sum;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function vanFor(User $user): array
    {
        $van = $this->ensureVan($user);

        return StockItem::query()->orderBy('name_en')->get()->map(function (StockItem $item) use ($van) {
            $serials = $item->serialized
                ? StockSerial::query()->where('item_id', $item->id)->where('location_id', $van->id)->where('status', 'in_stock')->get(['id', 'serial', 'mac'])
                : collect();
            $onHand = $item->serialized ? number_format($serials->count(), 3, '.', '') : $this->onHand($item, $van->id);

            if (bccomp($onHand, '0', 3) <= 0) {
                return null;
            }

            return [
                'id' => $item->id,
                'name_en' => $item->name_en,
                'name_ar' => $item->name_ar,
                'unit' => $item->unit,
                'serialized' => $item->serialized,
                'on_hand' => $onHand,
                'serials' => $serials,
            ];
        })->filter()->values()->all();
    }

    /**
     * @param  list<array{serial: string, mac?: string|null}>  $serials
     */
    private function normalize(StockItem $item, string $quantity, array $serials): string
    {
        if ($item->serialized) {
            $count = count($serials);

            if ($count < 1) {
                throw ValidationException::withMessages(['serial' => __('ui.inventory.serial_required')]);
            }

            return number_format($count, 3, '.', '');
        }

        return $this->quantityOf($item, $quantity);
    }

    private function quantityOf(StockItem $item, string $quantity, bool $allowZero = false): string
    {
        if (! is_numeric($quantity) || bccomp($quantity, '0', 3) < 0 || (! $allowZero && bccomp($quantity, '0', 3) === 0)) {
            throw ValidationException::withMessages(['quantity' => __('ui.inventory.quantity_invalid')]);
        }

        if ($item->unit === 'piece' && bccomp($quantity, (string) (int) $quantity, 3) !== 0) {
            throw ValidationException::withMessages(['quantity' => __('ui.inventory.whole')]);
        }

        return bcadd($quantity, '0', 3);
    }

    private function assertAvailable(StockItem $item, StockLocation $location, string $quantity, ?StockSerial $serial): void
    {
        if ($serial instanceof StockSerial) {
            if ((int) $serial->location_id !== (int) $location->id || $serial->status !== 'in_stock') {
                throw ValidationException::withMessages(['serial' => __('ui.inventory.serial_unavailable')]);
            }

            return;
        }

        $available = bcsub($this->onHand($item, $location->id), $this->held($item, $location->id), 3);

        if (bccomp($available, $quantity, 3) < 0) {
            throw ValidationException::withMessages(['quantity' => __('ui.inventory.short')]);
        }
    }

    private function held(StockItem $item, int $locationId): string
    {
        $sum = '0.000';

        StockReservation::query()
            ->where('item_id', $item->id)
            ->where('location_id', $locationId)
            ->where('status', 'held')
            ->pluck('quantity')
            ->each(function ($qty) use (&$sum): void {
                $sum = bcadd($sum, (string) $qty, 3);
            });

        return $sum;
    }

    public function releaseJob(Job $job, User $actor, ?Request $request): void
    {
        StockReservation::query()->where('job_id', $job->id)->where('status', 'held')->get()->each(function (StockReservation $row) use ($job, $actor, $request): void {
            $item = StockItem::query()->find($row->item_id);

            if (! $item instanceof StockItem) {
                return;
            }

            $quantity = (string) $row->quantity;
            $row->status = 'released';
            $row->save();
            $this->move($item, $quantity, 'release', (int) $row->location_id, null, $row->serial_id ? (int) $row->serial_id : null, $job->id, null, $actor, null, 'order cancelled');
            $this->audit->record('stock.released', $actor, [
                'item_id' => $item->id,
                'job_id' => $job->id,
                'customer_id' => $job->customer_id,
                'quantity' => $quantity,
            ], $request);
        });
    }

    private function move(
        StockItem $item,
        string $quantity,
        string $kind,
        ?int $from,
        ?int $to,
        ?int $serialId,
        ?int $jobId,
        ?int $serviceId,
        User $actor,
        ?int $approvedBy,
        ?string $reason,
        ?int $customerId = null,
        ?int $networkNodeId = null,
        ?int $expenseId = null,
    ): void {
        StockMovement::query()->create([
            'item_id' => $item->id,
            'serial_id' => $serialId,
            'from_location_id' => $from,
            'to_location_id' => $to,
            'quantity' => $quantity,
            'unit_cost_usd' => $item->unit_cost_usd,
            'kind' => $kind,
            'reason' => $reason,
            'job_id' => $jobId,
            'customer_id' => $customerId,
            'network_node_id' => $networkNodeId,
            'expense_id' => $expenseId,
            'customer_service_id' => $serviceId,
            'actor_id' => $actor->id,
            'approved_by' => $approvedBy,
            'created_at' => now(),
        ]);
    }

    private function watchLevel(StockItem $item): void
    {
        $warehouseIds = StockLocation::query()->where('kind', 'warehouse')->pluck('id');
        $total = '0.000';

        foreach ($warehouseIds as $id) {
            $total = bcadd($total, $this->onHand($item, (int) $id), 3);
        }

        $low = bccomp($total, (string) $item->reorder_level, 3) <= 0;

        if ($low && ! $item->low_stock_alerted) {
            $item->low_stock_alerted = true;
            $item->save();
            $people = User::query()->whereIn('role', ['warehouse', 'ops_manager'])->where('is_active', true)->get();

            if ($people->isNotEmpty()) {
                Notification::send($people, new WorkspaceNotification(
                    __('ui.notifications.low_stock_title', ['item' => $item->name_en]),
                    __('ui.notifications.low_stock_body', ['qty' => $total]),
                ));
            }
        }

        if (! $low && $item->low_stock_alerted) {
            $item->low_stock_alerted = false;
            $item->save();
        }
    }
}
