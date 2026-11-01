<?php

namespace App\Services\Opportunities;

class OpportunityMoney
{
    public function lbp(string $usd, string $rate): string
    {
        $raw = bcmul($usd, $rate, 4);
        $negative = bccomp($raw, '0', 4) < 0;
        $absolute = ltrim($raw, '-');
        $nudged = bcadd($absolute, '500', 4);
        $thousands = bcdiv($nudged, '1000', 0);

        return ($negative ? '-' : '').bcmul($thousands, '1000', 0);
    }
}
