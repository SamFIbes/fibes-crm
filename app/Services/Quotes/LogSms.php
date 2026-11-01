<?php

namespace App\Services\Quotes;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class LogSms implements SmsDriver
{
    public function send(string $phone, string $message, string $code, string $cacheKey): void
    {
        Cache::put($cacheKey, $code, now()->addMinutes(10));
        Log::info('sms.log', ['phone' => $phone, 'message' => $message]);
    }
}
