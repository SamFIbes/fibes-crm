<?php

namespace App\Services\Quotes;

use App\Models\DiscountLimit;
use App\Models\User;

class DiscountCap
{
    /**
     * A discount needs approval when the percent is over the role's percent
     * or the dollars are over the role's amount. Both ceilings apply.
     * No limit row means approval is required, except a super admin, who is unlimited.
     * A custom price always needs approval.
     *
     * @param  list<array{percent: string, amount: string, custom: bool, free: bool}>  $lines
     * @return array{needs: bool, type: string|null, percent: string, amount: string}
     */
    public function assess(User $user, array $lines, string $subtotal, string $discountTotal): array
    {
        $percent = '0.00';
        $amount = '0.00';
        $custom = false;
        $free = false;

        foreach ($lines as $line) {
            if (bccomp($line['percent'], $percent, 2) === 1) {
                $percent = $line['percent'];
            }

            if (bccomp($line['amount'], $amount, 2) === 1) {
                $amount = $line['amount'];
            }

            $custom = $custom || $line['custom'];
            $free = $free || $line['free'];
        }

        $totalPercent = bccomp($subtotal, '0', 2) === 1
            ? bcdiv(bcmul($discountTotal, '100', 4), $subtotal, 2)
            : '0.00';

        if ($custom) {
            return ['needs' => true, 'type' => 'custom_price', 'percent' => $percent, 'amount' => $amount];
        }

        if ($user->isSuperAdmin()) {
            return ['needs' => false, 'type' => null, 'percent' => $percent, 'amount' => $discountTotal];
        }

        $limit = DiscountLimit::query()->where('role', (string) $user->role)->first();

        if (! $limit instanceof DiscountLimit) {
            return ['needs' => true, 'type' => $free ? 'free_install' : 'quote_discount', 'percent' => $percent, 'amount' => $discountTotal];
        }

        $over = bccomp($percent, (string) $limit->line_percent, 2) === 1
            || bccomp($amount, (string) $limit->line_amount, 2) === 1
            || bccomp($totalPercent, (string) $limit->total_percent, 2) === 1
            || bccomp($discountTotal, (string) $limit->total_amount, 2) === 1;

        if (! $over) {
            return ['needs' => false, 'type' => null, 'percent' => $percent, 'amount' => $discountTotal];
        }

        return [
            'needs' => true,
            'type' => $free ? 'free_install' : 'quote_discount',
            'percent' => $percent,
            'amount' => $discountTotal,
        ];
    }
}
