<?php

namespace App\Services\Integrations;

use App\Services\Payments\WhishDriver;

interface WhishClient extends WhishDriver
{
    /**
     * No live call. A payment reference is still typed by hand.
     *
     * @return array{ok: bool, message: string}
     */
    public function testConnection(): array;
}
