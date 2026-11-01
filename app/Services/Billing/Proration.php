<?php

namespace App\Services\Billing;

class Proration
{
    public function __construct(private MoneyMath $money) {}

    public function portion(string $price, int $days, int $periodDays): string
    {
        $price = bcadd($price, '0', 2);

        if ($periodDays < 1 || $days >= $periodDays) {
            return $price;
        }

        if ($days < 1) {
            return '0.00';
        }

        return $this->money->round(bcdiv(bcmul($price, (string) $days, 6), (string) $periodDays, 6));
    }
}
