<?php

namespace App\Http\Controllers;

use App\Models\PriceChangeRequest;
use App\Models\Promotion;
use App\Models\User;
use App\Services\Approvals\ApprovalDesk;
use App\Services\Catalog\CatalogDesk;
use App\Support\Access\AccessGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CatalogController extends Controller
{
    public function index(Request $request, CatalogDesk $catalog, AccessGate $access): Response
    {
        $actor = $this->actor($request, $access);

        return Inertia::render('Catalog/Index', $catalog->page($actor));
    }

    public function products(Request $request, CatalogDesk $catalog, AccessGate $access): JsonResponse
    {
        $actor = $this->actor($request, $access);
        $page = $catalog->page($actor);

        return response()->json([
            'products' => $page['products'],
            'plans' => $page['plans'],
        ]);
    }

    public function storeProduct(Request $request, CatalogDesk $catalog, AccessGate $access): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        abort_unless($access->allows($actor, 'catalog.edit'), 403);
        $data = $request->validate([
            'sku' => ['required', 'string', 'max:40'],
            'kind' => ['required', 'in:plan,addon,equipment,installation,static_ip,one_time'],
            'service_type' => ['nullable', 'string', 'max:30'],
            'name_en' => ['required', 'string', 'max:160'],
            'name_ar' => ['required', 'string', 'max:160'],
            'offering' => ['nullable', 'in:sale,rental'],
            'cost_usd' => ['nullable', 'numeric', 'min:0'],
            'stock_item_id' => ['nullable', 'integer', 'exists:stock_items,id'],
            'down_mbps' => ['nullable', 'integer', 'min:0'],
            'up_mbps' => ['nullable', 'integer', 'min:0'],
            'burst_mbps' => ['nullable', 'integer', 'min:0'],
            'fup_gb' => ['nullable', 'integer', 'min:0'],
            'billing_cycle' => ['nullable', 'in:monthly,quarterly,yearly'],
            'radius_profile_name' => ['nullable', 'string', 'max:160'],
            'needs_survey' => ['nullable', 'boolean'],
            'min_term_months' => ['nullable', 'integer', 'min:0'],
            'is_public' => ['nullable', 'boolean'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'valid_from' => ['nullable', 'date'],
            'customer_type' => ['nullable', 'in:individual,business,reseller'],
            'note' => ['nullable', 'string', 'max:160'],
        ]);
        $catalog->createProduct($data, $actor, $request);

        return back()->with('status', __('ui.catalog.saved'));
    }

    public function storePrice(Request $request, CatalogDesk $catalog, AccessGate $access): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        abort_unless($access->allows($actor, 'catalog.prices.edit'), 403);
        $catalog->addPrice($this->priceInput($request), $actor, $request);

        return back()->with('status', __('ui.catalog.price_saved'));
    }

    public function requestPrice(Request $request, CatalogDesk $catalog, AccessGate $access): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        abort_unless($access->allows($actor, 'catalog.prices.request'), 403);
        $catalog->requestPrice($this->priceInput($request), $actor, $request);

        return back()->with('status', __('ui.catalog.requested'));
    }

    public function applyRequest(Request $request, PriceChangeRequest $priceChangeRequest, CatalogDesk $catalog, AccessGate $access): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        abort_unless($access->allows($actor, 'catalog.prices.edit'), 403);
        $catalog->applyRequest($priceChangeRequest, $actor, $request);

        return back()->with('status', __('ui.catalog.price_saved'));
    }

    public function storePromotion(Request $request, CatalogDesk $catalog, AccessGate $access): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        abort_unless($access->allows($actor, 'catalog.promotions.edit'), 403);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'name_en' => ['required', 'string', 'max:160'],
            'name_ar' => ['required', 'string', 'max:160'],
            'type' => ['required', 'in:percent,fixed,free_months,free_install,free_equipment'],
            'value' => ['required', 'numeric', 'min:0'],
            'valid_from' => ['required', 'date'],
            'valid_to' => ['nullable', 'date'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'per_customer_limit' => ['nullable', 'integer', 'min:1'],
            'stackable' => ['nullable', 'boolean'],
            'requires_approval' => ['nullable', 'boolean'],
            'min_term_months' => ['nullable', 'integer', 'min:0'],
            'campaign_id' => ['nullable', 'integer', 'exists:campaigns,id'],
            'customer_types' => ['nullable', 'array'],
            'customer_types.*' => ['in:individual,business,reseller'],
        ]);
        $catalog->createPromotion($data, $actor, $request);

        return back()->with('status', __('ui.catalog.promo_saved'));
    }

    public function preview(Request $request, CatalogDesk $catalog, AccessGate $access): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        abort_unless($access->allows($actor, 'catalog.view'), 403);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'amount' => ['required', 'numeric', 'min:0'],
            'on' => ['nullable', 'date'],
            'product_id' => ['nullable', 'integer'],
            'customer_type' => ['nullable', 'string', 'max:30'],
        ]);
        $result = $catalog->preview([(string) $data['code']], (string) $data['amount'], [
            'on' => $data['on'] ?? now()->toDateString(),
            'product_id' => $data['product_id'] ?? null,
            'customer_type' => $data['customer_type'] ?? null,
        ]);
        $needs = collect($result['applied'])->contains(fn (array $row) => ! empty($row['needs_approval']));

        if ($needs) {
            $promo = Promotion::query()->whereRaw('upper(code) = ?', [strtoupper((string) $data['code'])])->first();
            app(ApprovalDesk::class)->open($actor, 'promo_approval', 'promotion', $promo?->id, [
                'code' => strtoupper((string) $data['code']),
            ], strtoupper((string) $data['code']), $request);

            return back()->with('status', __('ui.approvals.waiting'));
        }

        return back()->with('status', __('ui.catalog.promo_result', [
            'code' => $result['applied'][0]['code'] ?? $data['code'],
            'final' => $result['final'],
        ]));
    }

    public function saveLimit(Request $request, CatalogDesk $catalog, AccessGate $access): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        abort_unless($access->allows($actor, 'catalog.edit'), 403);
        $data = $request->validate([
            'role' => ['required', 'string', 'max:40'],
            'line_percent' => ['required', 'numeric', 'min:0'],
            'line_amount' => ['required', 'numeric', 'min:0'],
            'total_percent' => ['required', 'numeric', 'min:0'],
            'total_amount' => ['required', 'numeric', 'min:0'],
        ]);
        $catalog->saveLimit($data, $actor, $request);

        return back()->with('status', __('ui.catalog.saved'));
    }

    public function migrate(Request $request, CatalogDesk $catalog, AccessGate $access): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        abort_unless($access->allows($actor, 'catalog.edit'), 403);
        $catalog->migrateBlocked($actor, $request);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function priceInput(Request $request): array
    {
        return $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'price' => ['required', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'valid_from' => ['required', 'date'],
            'valid_to' => ['nullable', 'date'],
            'customer_type' => ['nullable', 'in:individual,business,reseller'],
            'area_id' => ['nullable', 'integer', 'exists:areas,id'],
            'note' => ['nullable', 'string', 'max:160'],
        ]);
    }

    private function actor(Request $request, AccessGate $access): User
    {
        $actor = $access->effective($request);
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
