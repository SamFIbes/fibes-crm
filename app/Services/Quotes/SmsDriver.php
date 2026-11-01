<?php

namespace App\Services\Quotes;

interface SmsDriver
{
    public function send(string $phone, string $message, string $code, string $cacheKey): void;
}
