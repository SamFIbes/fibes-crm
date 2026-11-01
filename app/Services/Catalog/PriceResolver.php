<?php

namespace App\Services\Catalog;

use App\Models\ExchangeRate;
use App\Models\PriceListItem;
use App\Services\Opportunities\OpportunityMoney;

class PriceResolver
{
    public function __construct(private OpportunityMoney $money) {}

    /**
     * @return array{id: int, price: string, currency: string, lbp: string|null, valid_from: string, note: string|null}|null
     */
    public function resolve(int $productId, string $on, ?string $customerType = null, ?int $areaId = null): ?array
    {
        $rows = PriceListItem::query()
            ->where('product_id', $productId)
            ->whereDate('valid_from', '<=', $on)
            ->where(function ($query) use ($on): void {
                $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $on);
            })
            ->orderByDesc('valid_from')
            ->orderByDesc('id')
            ->get();

        $best = null;
        $bestScore = -1;

        foreach ($rows as $row) {
            if ($row->customer_type !== null && $row->customer_type !== $customerType) {
                continue;
            }

            if ($row->area_id !== null && (int) $row->area_id !== (int) $areaId) {
                continue;
            }

            $score = ($row->area_id !== null ? 2 : 0) + ($row->customer_type !== null ? 1 : 0);

            if ($score > $bestScore) {
                $best = $row;
                $bestScore = $score;
            }
        }

        if (! $best instanceof PriceListItem) {
            return null;
        }

        $rate = ExchangeRate::query()->latest('id')->value('usd_lbp');

        return [
            'id' => $best->id,
            'price' => (string) $best->price,
            'currency' => $best->currency,
            'lbp' => $best->currency === 'USD' && is_string($rate) ? $this->money->lbp((string) $best->price, $rate) : null,
            'valid_from' => $best->valid_from?->toDateString() ?? $on,
            'note' => $best->note,
        ];
    }
}
