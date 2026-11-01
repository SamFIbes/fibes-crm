<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Models\StockItem;
use App\Models\StockLocation;
use App\Models\Supplier;
use App\Services\Orders\OrderDesk;
use App\Services\Orders\OrderMachine;
use App\Services\Orders\PurchasingDesk;
use App\Support\Access\AccessGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class OrderController extends Controller
{
    public function index(Request $request, AccessGate $access, OrderDesk $orders, PurchasingDesk $purchasing): Response
    {
        $actor = $access->effective($request);
        abort_unless($actor !== null, 403);
        $seeCost = $access->allows($actor, 'billing.view_cost');

        return Inertia::render('Orders/Index', [
            'orders' => $orders->visible($actor)->with(['customer:id,name_en,name_ar', 'lines'])->latest('id')->limit(40)->get()->map(fn (Order $order) => $this->row($order))->values(),
            'purchases' => $access->allows($actor, 'purchasing.create') || $access->allows($actor, 'purchasing.receive')
                ? PurchaseOrder::query()->with('lines.item')->latest('id')->limit(20)->get()->map(fn (PurchaseOrder $po) => $this->purchase($po, $seeCost))->values()
                : [],
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name']),
            'items' => StockItem::query()->orderBy('name_en')->get()->map(fn (StockItem $item) => [
                'id' => $item->id,
                'sku' => $item->sku,
                'name_en' => $item->name_en,
                'name_ar' => $item->name_ar,
                'cost' => $seeCost ? (string) $item->unit_cost_usd : null,
            ])->values(),
            'locations' => StockLocation::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'kind']),
            'can_create' => $access->allows($actor, 'orders.create'),
            'can_purchase' => $access->allows($actor, 'purchasing.create'),
            'can_receive' => $access->allows($actor, 'purchasing.receive'),
            'see_cost' => $seeCost,
        ]);
    }

    public function show(Request $request, Order $order, AccessGate $access, OrderDesk $orders): Response
    {
        $actor = $access->effective($request);
        abort_unless($actor !== null, 403);
        $order = $orders->find($actor, $order->id);
        $order->load(['lines', 'jobs:id,number,type,status,order_id', 'customer:id,name_en,name_ar,whatsapp_consent,whatsapp_e164,email']);

        return Inertia::render('Orders/Show', [
            'order' => $this->detail($order, $orders->whatsapp($order)),
            'can_move' => $access->allows($actor, 'orders.create') || $access->allows($actor, 'orders.activate') || $access->allows($actor, 'jobs.schedule'),
            'can_activate' => $access->allows($actor, 'orders.activate') || $access->allows($actor, 'services.radius_fields.edit'),
            'can_reserve' => $access->allows($actor, 'inventory.reserve'),
        ]);
    }

    public function store(Request $request, AccessGate $access, OrderDesk $orders): RedirectResponse
    {
        $actor = $access->effective($request);
        abort_unless($actor !== null, 403);
        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'customer_service_id' => ['nullable', 'integer'],
            'type' => ['required', 'in:upgrade,downgrade,relocation,addon,termination,reactivation'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
            'return_charge' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $order = $orders->openChange($actor, $data, $request);

        return redirect()->route('orders.show', $order)->with('status', $order->status === 'pending_approval' ? __('ui.orders.pending_note') : __('ui.orders.saved'));
    }

    public function transition(Request $request, Order $order, AccessGate $access, OrderDesk $orders): RedirectResponse
    {
        $actor = $access->effective($request);
        abort_unless($actor !== null, 403);
        abort_unless(
            $access->allows($actor, 'orders.create') || $access->allows($actor, 'orders.activate') || $access->allows($actor, 'jobs.schedule'),
            403,
        );
        $order = $orders->find($actor, $order->id);
        $data = $request->validate([
            'to' => ['required', 'string', 'max:40'],
            'reason' => ['nullable', 'string', 'max:500'],
            'radius_username' => ['nullable', 'string', 'max:80'],
            'vlan' => ['nullable', 'string', 'max:40'],
            'static_ip' => ['nullable', 'string', 'max:40'],
            'mac' => ['nullable', 'string', 'max:40'],
            'billing_confirmed' => ['nullable', 'boolean'],
            'deposit_paid' => ['nullable', 'boolean'],
        ]);
        $orders->transition($order, $data['to'], $actor, $request, $data);
        $link = $orders->whatsapp($order->fresh() ?? $order);

        return back()->with('status', __('ui.orders.saved'))->with('link', $link);
    }

    public function reserve(Request $request, Order $order, AccessGate $access, OrderDesk $orders): RedirectResponse
    {
        $actor = $access->effective($request);
        abort_unless($actor !== null, 403);
        $order = $orders->find($actor, $order->id);
        $data = $request->validate([
            'item_id' => ['required', 'integer', 'exists:stock_items,id'],
            'location_id' => ['required', 'integer', 'exists:stock_locations,id'],
            'quantity' => ['required', 'numeric', 'min:0.001'],
        ]);
        $orders->reserve($order, $data, $actor, $request);

        return back()->with('status', __('ui.orders.saved'));
    }

    public function export(Request $request, AccessGate $access, PurchasingDesk $purchasing): HttpResponse
    {
        $actor = $access->effective($request);
        abort_unless($actor !== null, 403);

        return response($purchasing->export($actor, $request), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="stock-movements.csv"',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Order $order): array
    {
        return [
            'id' => $order->id,
            'number' => $order->number,
            'type' => $order->type,
            'status' => $order->status,
            'customer' => $order->customer?->name_en,
            'customer_ar' => $order->customer?->name_ar,
            'total' => (string) $order->total,
            'currency' => $order->currency,
            'sla_due' => $order->sla_due?->format('d/m/Y'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Order $order, ?string $whatsapp): array
    {
        $next = OrderMachine::ALLOWED[$order->status] ?? [];

        return [
            'id' => $order->id,
            'number' => $order->number,
            'type' => $order->type,
            'status' => $order->status,
            'customer' => $order->customer?->name_en,
            'customer_ar' => $order->customer?->name_ar,
            'total' => (string) $order->total,
            'currency' => $order->currency,
            'rate' => (string) $order->exchange_rate,
            'sla_due' => $order->sla_due?->format('d/m/Y'),
            'return_charge' => (string) $order->return_charge,
            'notes' => $order->notes,
            'whatsapp' => $whatsapp,
            'consent' => (bool) $order->customer?->whatsapp_consent,
            'next' => $next,
            'steps' => collect(OrderMachine::PATH)->map(fn (string $step) => [
                'key' => $step,
                'done' => $this->reached($order->status, $step),
                'current' => $order->status === $step,
            ])->all(),
            'lines' => $order->lines->map(fn ($line) => [
                'description' => $line->description,
                'qty' => (string) $line->qty,
                'total' => (string) $line->line_total,
                'recurring' => (bool) $line->is_recurring,
            ])->values(),
            'jobs' => $order->jobs->map(fn ($job) => [
                'id' => $job->id,
                'number' => $job->number,
                'type' => $job->type,
                'status' => $job->status,
            ])->values(),
        ];
    }

    private function reached(string $status, string $step): bool
    {
        $path = OrderMachine::PATH;
        $at = array_search($status, $path, true);
        $want = array_search($step, $path, true);

        if ($at === false || $want === false) {
            return false;
        }

        return $want < $at || $status === 'completed';
    }

    /**
     * @return array<string, mixed>
     */
    private function purchase(PurchaseOrder $po, bool $seeCost): array
    {
        return [
            'id' => $po->id,
            'number' => $po->number,
            'status' => $po->status,
            'erp_ref' => $po->erp_ref,
            'total' => $seeCost ? (string) $po->total : null,
            'lines' => $po->lines->map(fn ($line) => [
                'id' => $line->id,
                'item' => $line->item?->name_en,
                'qty' => (string) $line->qty,
                'received' => (string) $line->received_qty,
                'cost' => $seeCost ? (string) $line->unit_cost : null,
            ])->values(),
        ];
    }
}
