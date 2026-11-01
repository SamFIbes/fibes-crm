<?php

namespace App\Services\Payments;

interface WhishDriver
{
    /**
     * @return array{status: string}
     */
    public function charge(string $reference, string $amount, string $currency): array;
}
