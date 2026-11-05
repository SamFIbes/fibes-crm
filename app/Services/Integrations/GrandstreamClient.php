<?php

namespace App\Services\Integrations;

interface GrandstreamClient
{
    /**
     * The screen-pop stays on FIBES_TELEPHONY_SECRET. This does not call the PBX.
     *
     * @return array{ok: bool, message: string}
     */
    public function testConnection(): array;
}
