<?php

namespace App\Logging;

use Monolog\LogRecord;

class RedactPii
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $context = $record->context;

        foreach (['email', 'phone', 'phones', 'password', 'name_en', 'name_ar', 'whatsapp', 'whatsapp_e164'] as $key) {
            unset($context[$key]);
        }

        return $record->with(message: self::scrub($record->message), context: $context);
    }

    public static function scrub(string $message): string
    {
        $message = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[email]', $message) ?? $message;

        return preg_replace('/\+\d{6,}/', '[phone]', $message) ?? $message;
    }
}
