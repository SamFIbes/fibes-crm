<?php

namespace App\Services\Integrations;

use App\Models\Invoice;
use App\Services\Payments\WhishDriver;

interface WhishClient extends WhishDriver
{
    public function ready(): bool;

    /**
     * @return array{ok: bool, message: string}
     */
    public function testConnection(): array;

    /**
     * @return array{id: string, url: string, reference: string}
     */
    public function createCheckout(Invoice $invoice): array;
}
