<?php

namespace App\Services\Billing;

class MoneyMath
{
    public function round(string $raw): string
    {
        $negative = str_starts_with($raw, '-');
        $absolute = ltrim($raw, '-');
        $absolute = bcadd($absolute, '0', 6);
        [$whole, $fraction] = array_pad(explode('.', $absolute, 2), 2, '000000');
        $fraction = str_pad(substr($fraction, 0, 6), 6, '0');
        $cents = (int) substr($fraction, 0, 2);
        $next = (int) $fraction[2];

        if ($next >= 5) {
            $cents++;
        }

        if ($cents >= 100) {
            $whole = bcadd($whole, '1', 0);
            $cents = 0;
        }

        return ($negative ? '-' : '').$whole.'.'.str_pad((string) $cents, 2, '0', STR_PAD_LEFT);
    }

    public function tax(string $net, string $percent): string
    {
        return $this->round(bcdiv(bcmul($net, $percent, 6), '100', 6));
    }

    public function add(string $left, string $right): string
    {
        return bcadd($left, $right, 2);
    }
}
