<?php

namespace App\Services\Catalog;

use App\Models\Campaign;
use App\Models\CustomerService;
use App\Models\DiscountLimit;
use App\Models\Plan;
use App\Models\PriceChangeRequest;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\User;
use App\Services\Approvals\ApprovalDesk;
use App\Services\Audit\AuditLogger;
use App\Support\Access\AccessGate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CatalogDesk
{
    public function __construct(
        private PriceResolver $prices,
        private PromotionEngine $promotions,
        private AuditLogger $audit,
        private AccessGate $access,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function page(User $actor): array
    {
        $seeCost = $this->access->allows($actor, 'billing.view_cost');
        $on = now()->toDateString();

        return [
            'products' => Product::query()->with(['plan', 'stockItem:id,sku,name_en,name_ar'])->whereNull('archived_at')->orderBy('name_en')->get()->map(fn (Product $product) => $this->presentProduct($product, $seeCost, $on))->values(),
            'prices' => PriceListItem::query()->with('product:id,name_en,name_ar,sku')->latest('id')->limit(80)->get()->map(fn (PriceListItem $row) => [
                'id' => $row->id,
                'product_id' => $row->product_id,
                'sku' => $row->product?->sku,
                'name_en' => $row->product?->name_en,
                'name_ar' => $row->product?->name_ar,
                'price' => (string) $row->price,
                'currency' => $row->currency,
                'valid_from' => $row->valid_from?->toDateString(),
                'valid_to' => $row->valid_to?->toDateString(),
                'customer_type' => $row->customer_type,
                'area_id' => $row->area_id,
                'note' => $row->note,
            ])->values(),
            'requests' => PriceChangeRequest::query()->latest('id')->limit(40)->get()->map(fn (PriceChangeRequest $row) => [
                'id' => $row->id,
                'product_id' => $row->product_id,
                'price' => (string) $row->price,
                'currency' => $row->currency,
                'valid_from' => $row->valid_from?->toDateString(),
                'customer_type' => $row->customer_type,
                'status' => $row->status,
                'note' => $row->note,
                'requested_by' => $row->requested_by,
            ])->values(),
            'promotions' => Promotion::query()->latest('id')->get()->map(fn (Promotion $promo) => [
                'id' => $promo->id,
                'code' => $promo->code,
                'name_en' => $promo->name_en,
                'name_ar' => $promo->name_ar,
                'type' => $promo->type,
                'value' => (string) $promo->value,
                'valid_from' => $promo->valid_from?->toDateString(),
                'valid_to' => $promo->valid_to?->toDateString(),
                'stackable' => $promo->stackable,
                'requires_approval' => $promo->requiresApproval(),
                'is_active' => $promo->is_active,
            ])->values(),
            'limits' => DiscountLimit::query()->orderBy('role')->get(),
            'plans' => $this->choices($on),
            'campaigns' => Campaign::query()->orderBy('name')->get(['id', 'name']),
            'can_edit' => $this->access->allows($actor, 'catalog.edit'),
            'can_price' => $this->access->allows($actor, 'catalog.prices.edit'),
            'can_request' => $this->access->allows($actor, 'catalog.prices.request'),
            'can_promote' => $this->access->allows($actor, 'catalog.promotions.edit'),
            'see_cost' => $seeCost,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function choices(string $on, ?string $customerType = null, ?int $areaId = null): array
    {
        return Plan::query()->with('product')->where('is_public', true)->orderBy('id')->get()->map(function (Plan $plan) use ($on, $customerType, $areaId) {
            $price = $plan->product ? $this->prices->resolve($plan->product_id, $on, $customerType, $areaId) : null;

            return [
                'id' => $plan->id,
                'product_id' => $plan->product_id,
                'name' => $plan->product?->name_en,
                'name_ar' => $plan->product?->name_ar,
                'service_type' => $plan->product?->service_type,
                'down_mbps' => $plan->down_mbps,
                'up_mbps' => $plan->up_mbps,
                'price' => $price['price'] ?? null,
                'price_lbp' => $price['lbp'] ?? null,
                'needs_survey' => $plan->needs_survey,
            ];
        })->values()->all();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function createProduct(array $input, User $actor, Request $request): Product
    {
        return DB::transaction(function () use ($input, $actor, $request) {
            $product = Product::query()->create([
                'sku' => strtoupper(trim((string) $input['sku'])),
                'kind' => $input['kind'],
                'service_type' => ($input['service_type'] ?? null) ?: null,
                'name_en' => $input['name_en'],
                'name_ar' => $input['name_ar'],
                'offering' => ($input['offering'] ?? null) ?: null,
                'cost_usd' => $this->access->allows($actor, 'billing.view_cost') ? (($input['cost_usd'] ?? null) ?: null) : null,
                'stock_item_id' => ($input['stock_item_id'] ?? null) ?: null,
                'is_active' => true,
            ]);

            if ($input['kind'] === 'plan') {
                Plan::query()->create([
                    'product_id' => $product->id,
                    'down_mbps' => ($input['down_mbps'] ?? null) ?: null,
                    'up_mbps' => ($input['up_mbps'] ?? null) ?: null,
                    'burst_mbps' => ($input['burst_mbps'] ?? null) ?: null,
                    'fup_gb' => ($input['fup_gb'] ?? null) ?: null,
                    'billing_cycle' => ($input['billing_cycle'] ?? null) ?: 'monthly',
                    'radius_profile_name' => ($input['radius_profile_name'] ?? null) ?: $product->name_en,
                    'needs_survey' => (bool) ($input['needs_survey'] ?? false),
                    'min_term_months' => (int) ($input['min_term_months'] ?? 0),
                    'is_public' => (bool) ($input['is_public'] ?? true),
                ]);
            }

            if (filled($input['price'] ?? null)) {
                $this->addPrice([
                    'product_id' => $product->id,
                    'price' => $input['price'],
                    'currency' => $input['currency'] ?? 'USD',
                    'valid_from' => $input['valid_from'] ?? now()->toDateString(),
                    'customer_type' => $input['customer_type'] ?? null,
                    'note' => $input['note'] ?? 'To confirm by Sam',
                ], $actor, $request);
            }

            $this->audit->record('catalog.product.created', $actor, [
                'product_id' => $product->id,
                'sku' => $product->sku,
            ], $request);

            return $product;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function addPrice(array $input, User $actor, Request $request): PriceListItem
    {
        $row = PriceListItem::query()->create([
            'product_id' => $input['product_id'],
            'price' => $input['price'],
            'currency' => strtoupper((string) ($input['currency'] ?? 'USD')),
            'valid_from' => $input['valid_from'],
            'valid_to' => $input['valid_to'] ?? null,
            'customer_type' => ($input['customer_type'] ?? null) ?: null,
            'area_id' => ($input['area_id'] ?? null) ?: null,
            'note' => $input['note'] ?? null,
            'created_by' => $actor->id,
        ]);
        $this->audit->record('catalog.price.added', $actor, [
            'price_id' => $row->id,
            'product_id' => $row->product_id,
            'price' => (string) $row->price,
            'valid_from' => $row->valid_from?->toDateString(),
        ], $request);

        return $row;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function requestPrice(array $input, User $actor, Request $request): PriceChangeRequest
    {
        $row = PriceChangeRequest::query()->create([
            'product_id' => $input['product_id'],
            'price' => $input['price'],
            'currency' => strtoupper((string) ($input['currency'] ?? 'USD')),
            'valid_from' => $input['valid_from'],
            'customer_type' => ($input['customer_type'] ?? null) ?: null,
            'area_id' => ($input['area_id'] ?? null) ?: null,
            'note' => $input['note'] ?? null,
            'status' => 'pending',
            'requested_by' => $actor->id,
        ]);
        $this->audit->record('catalog.price.requested', $actor, [
            'request_id' => $row->id,
            'product_id' => $row->product_id,
            'price' => (string) $row->price,
        ], $request);

        return $row;
    }

    public function applyRequest(PriceChangeRequest $change, User $actor, Request $request): ?PriceListItem
    {
        if ($change->status !== 'pending') {
            throw ValidationException::withMessages(['price' => __('ui.catalog.request_closed')]);
        }

        if ((int) $change->requested_by === (int) $actor->id) {
            throw ValidationException::withMessages(['price' => __('ui.catalog.own_request')]);
        }

        $requester = User::query()->find($change->requested_by) ?? $actor;
        app(ApprovalDesk::class)->open($requester, 'price_list', 'price_change', $change->id, [
            'request_id' => $change->id,
            'amount' => (string) $change->price,
        ], (string) $change->price, $request);

        return null;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function createPromotion(array $input, User $actor, Request $request): Promotion
    {
        $promo = Promotion::query()->create([
            'code' => strtoupper(trim((string) $input['code'])),
            'name_en' => $input['name_en'],
            'name_ar' => $input['name_ar'],
            'type' => $input['type'],
            'value' => $input['value'],
            'applies_to' => [
                'product_ids' => array_values(array_filter(array_map('intval', (array) ($input['product_ids'] ?? [])))),
                'plan_ids' => [],
                'area_ids' => array_values(array_filter(array_map('intval', (array) ($input['area_ids'] ?? [])))),
                'customer_types' => array_values(array_filter((array) ($input['customer_types'] ?? []))),
            ],
            'valid_from' => $input['valid_from'],
            'valid_to' => $input['valid_to'] ?: null,
            'max_uses' => $input['max_uses'] ?: null,
            'per_customer_limit' => $input['per_customer_limit'] ?: null,
            'stackable' => (bool) ($input['stackable'] ?? false),
            'requires_approval' => (bool) ($input['requires_approval'] ?? false),
            'min_term_months' => (int) ($input['min_term_months'] ?? 0),
            'is_active' => true,
        ]);

        if (filled($input['campaign_id'] ?? null)) {
            Campaign::query()->whereKey($input['campaign_id'])->update(['promo_code_id' => $promo->id]);
        }

        $this->audit->record('catalog.promotion.created', $actor, [
            'promotion_id' => $promo->id,
            'code' => $promo->code,
        ], $request);

        return $promo;
    }

    /**
     * @param  list<string>  $codes
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function preview(array $codes, string $amount, array $context): array
    {
        $result = $this->promotions->evaluate($codes, $amount, $context);
        $expired = collect($result['refused'])->first(fn (array $row) => $row['message'] === __('ui.catalog.promo_expired'));

        if ($expired !== null && $result['applied'] === []) {
            throw ValidationException::withMessages(['code' => $expired['message']]);
        }

        if ($result['refused'] !== [] && $result['applied'] === []) {
            throw ValidationException::withMessages(['code' => $result['refused'][0]['message']]);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function saveLimit(array $input, User $actor, Request $request): DiscountLimit
    {
        $limit = DiscountLimit::query()->updateOrCreate(['role' => $input['role']], [
            'line_percent' => $input['line_percent'],
            'line_amount' => $input['line_amount'],
            'total_percent' => $input['total_percent'],
            'total_amount' => $input['total_amount'],
            'currency' => 'USD',
        ]);
        $this->audit->record('catalog.limit.saved', $actor, [
            'role' => $limit->role,
            'line_percent' => (string) $limit->line_percent,
            'total_percent' => (string) $limit->total_percent,
        ], $request);

        return $limit;
    }

    public function migrateBlocked(User $actor, Request $request): void
    {
        $service = CustomerService::query()->whereNotNull('plan_id')->latest('id')->first();
        $planId = $service?->plan_id;
        $productId = $planId ? Plan::query()->whereKey($planId)->value('product_id') : null;
        app(ApprovalDesk::class)->open($actor, 'plan_migration', 'plan', $planId ? (int) $planId : null, [
            'plan_id' => $planId,
            'product_id' => $productId,
        ], __('ui.catalog.migrate'), $request);
        $this->audit->record('catalog.migrate_blocked', $actor, [
            'reason' => 'waiting',
            'plan_id' => $planId,
        ], $request);

        throw ValidationException::withMessages(['migrate' => __('ui.approvals.waiting')]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentProduct(Product $product, bool $seeCost, string $on): array
    {
        $price = $this->prices->resolve($product->id, $on);
        $row = [
            'id' => $product->id,
            'sku' => $product->sku,
            'kind' => $product->kind,
            'service_type' => $product->service_type,
            'name_en' => $product->name_en,
            'name_ar' => $product->name_ar,
            'offering' => $product->offering,
            'is_active' => $product->is_active,
            'stock_sku' => $product->stockItem?->sku,
            'plan_id' => $product->plan?->id,
            'down_mbps' => $product->plan?->down_mbps,
            'up_mbps' => $product->plan?->up_mbps,
            'burst_mbps' => $product->plan?->burst_mbps,
            'radius_profile_name' => $product->plan?->radius_profile_name,
            'price' => $price['price'] ?? null,
            'price_lbp' => $price['lbp'] ?? null,
            'price_note' => $price['note'] ?? null,
        ];

        if ($seeCost) {
            $row['cost_usd'] = $product->cost_usd === null ? null : (string) $product->cost_usd;
        }

        return $row;
    }
}
