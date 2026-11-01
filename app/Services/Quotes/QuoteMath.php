<?php

namespace App\Services\Quotes;

class QuoteMath
{
    /**
     * @return array{gross: string, discount: string, percent: string, net: string, tax: string, total: string}
     */
    public function line(string $qty, string $unit, string $discountPct, string $taxRate): array
    {
        $qty = $this->money($qty);
        $unit = $this->money($unit);
        $discountPct = $this->money($discountPct);
        $taxRate = $this->money($taxRate);
        $gross = bcmul($qty, $unit, 2);
        $discount = bcdiv(bcmul($gross, $discountPct, 4), '100', 2);

        if (bccomp($discount, $gross, 2) > 0) {
            $discount = $gross;
        }

        $net = bcsub($gross, $discount, 2);
        $tax = bcdiv(bcmul($net, $taxRate, 4), '100', 2);
        $percent = bccomp($gross, '0', 2) === 1
            ? bcdiv(bcmul($discount, '100', 4), $gross, 2)
            : $discountPct;

        return [
            'gross' => $gross,
            'discount' => $discount,
            'percent' => $percent,
            'net' => $net,
            'tax' => $tax,
            'total' => bcadd($net, $tax, 2),
        ];
    }

    /**
     * @param  list<array{gross: string, discount: string, tax: string, total: string}>  $lines
     * @return array{subtotal: string, discount: string, tax: string, total: string}
     */
    public function sum(array $lines): array
    {
        $subtotal = '0.00';
        $discount = '0.00';
        $tax = '0.00';
        $total = '0.00';

        foreach ($lines as $line) {
            $subtotal = bcadd($subtotal, $line['gross'], 2);
            $discount = bcadd($discount, $line['discount'], 2);
            $tax = bcadd($tax, $line['tax'], 2);
            $total = bcadd($total, $line['total'], 2);
        }

        return compact('subtotal', 'discount', 'tax', 'total');
    }

    public function money(string $value): string
    {
        if (! is_numeric($value)) {
            return '0.00';
        }

        return bcadd($value, '0', 2);
    }
}
