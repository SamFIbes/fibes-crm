<?php

namespace App\Services\Catalog;

use App\Models\Promotion;
use App\Models\PromotionUse;

class PromotionEngine
{
    /**
     * @param  list<string>  $codes
     * @param  array{on?: string, product_id?: int|null, plan_id?: int|null, area_id?: int|null, customer_type?: string|null, customer_id?: int|null}  $context
     * @return array{amount: string, final: string, saving: string, applied: list<array<string, mixed>>, refused: list<array{code: string, message: string}>}
     */
    public function evaluate(array $codes, string $amount, array $context = []): array
    {
        $amount = bcadd($amount, '0', 2);
        $on = $context['on'] ?? now()->toDateString();
        $offers = [];
        $refused = [];

        foreach ($codes as $code) {
            $promo = Promotion::query()->whereRaw('upper(code) = ?', [strtoupper(trim($code))])->first();

            if (! $promo instanceof Promotion || ! $promo->is_active) {
                $refused[] = ['code' => strtoupper(trim($code)), 'message' => __('ui.catalog.promo_missing')];

                continue;
            }

            $reason = $this->refusal($promo, $on, $context);

            if ($reason !== null) {
                $refused[] = ['code' => $promo->code, 'message' => $reason];

                continue;
            }

            $offers[] = $promo;
        }

        $stack = $offers !== [] && collect($offers)->every(fn (Promotion $promo) => $promo->stackable);
        $chosen = $stack ? $offers : ($offers === [] ? [] : [$this->best($offers, $amount, $context)]);
        $final = $amount;
        $applied = [];

        foreach ($chosen as $promo) {
            if (! $promo instanceof Promotion) {
                continue;
            }

            $saving = $this->saving($promo, $final, $context);

            if ($promo->requiresApproval()) {
                $applied[] = [
                    'code' => $promo->code,
                    'saving' => $saving,
                    'needs_approval' => true,
                    'applied' => false,
                ];

                continue;
            }

            $final = bcsub($final, $saving, 2);
            $applied[] = [
                'code' => $promo->code,
                'saving' => $saving,
                'needs_approval' => false,
                'applied' => true,
            ];
        }

        if (bccomp($final, '0', 2) < 0) {
            $final = '0.00';
        }

        return [
            'amount' => $amount,
            'final' => $final,
            'saving' => bcsub($amount, $final, 2),
            'applied' => $applied,
            'refused' => $refused,
        ];
    }

    /**
     * @param  array{on?: string, product_id?: int|null, plan_id?: int|null, area_id?: int|null, customer_type?: string|null, customer_id?: int|null}  $context
     */
    private function refusal(Promotion $promo, string $on, array $context): ?string
    {
        if ($promo->valid_from->toDateString() > $on) {
            return __('ui.catalog.promo_soon');
        }

        if ($promo->valid_to !== null && $promo->valid_to->toDateString() < $on) {
            return __('ui.catalog.promo_expired');
        }

        if ($promo->max_uses !== null && $promo->uses()->count() >= $promo->max_uses) {
            return __('ui.catalog.promo_used_up');
        }

        $customerId = $context['customer_id'] ?? null;

        if ($promo->per_customer_limit !== null && $customerId) {
            $used = PromotionUse::query()->where('promotion_id', $promo->id)->where('customer_id', $customerId)->count();

            if ($used >= $promo->per_customer_limit) {
                return __('ui.catalog.promo_customer_limit');
            }
        }

        $rules = is_array($promo->applies_to) ? $promo->applies_to : [];

        if ($this->misses($rules['product_ids'] ?? [], $context['product_id'] ?? null)) {
            return __('ui.catalog.promo_not_eligible');
        }

        if ($this->misses($rules['plan_ids'] ?? [], $context['plan_id'] ?? null)) {
            return __('ui.catalog.promo_not_eligible');
        }

        if ($this->misses($rules['area_ids'] ?? [], $context['area_id'] ?? null)) {
            return __('ui.catalog.promo_not_eligible');
        }

        $types = $rules['customer_types'] ?? [];

        if (is_array($types) && $types !== [] && ! in_array($context['customer_type'] ?? '', $types, true)) {
            return __('ui.catalog.promo_not_eligible');
        }

        return null;
    }

    /**
     * @param  list<Promotion>  $offers
     * @param  array<string, mixed>  $context
     */
    private function best(array $offers, string $amount, array $context): Promotion
    {
        $winner = $offers[0];
        $best = $this->saving($winner, $amount, $context);

        foreach ($offers as $promo) {
            $saving = $this->saving($promo, $amount, $context);
            $better = bccomp($saving, $best, 2) > 0;
            $tie = bccomp($saving, $best, 2) === 0 && strcmp($promo->code, $winner->code) < 0;

            if ($better || $tie) {
                $winner = $promo;
                $best = $saving;
            }
        }

        return $winner;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function saving(Promotion $promo, string $amount, array $context): string
    {
        $value = (string) $promo->value;
        $saving = match ($promo->type) {
            'percent' => bcdiv(bcmul($amount, $value, 4), '100', 2),
            'fixed' => $value,
            'free_months' => bcmul($amount, $value, 2),
            'free_install', 'free_equipment' => $value,
            default => '0.00',
        };

        if (bccomp($saving, $amount, 2) > 0 && ! in_array($promo->type, ['free_months'], true)) {
            return $amount;
        }

        if (bccomp($saving, '0', 2) < 0) {
            return '0.00';
        }

        return $saving;
    }

    private function misses(mixed $ids, int|string|null $value): bool
    {
        if (! is_array($ids) || $ids === []) {
            return false;
        }

        $wanted = array_map(fn ($id) => (int) $id, $ids);

        return ! in_array((int) $value, $wanted, true);
    }
}
