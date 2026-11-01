<?php

namespace App\Services\Observability;

use App\Logging\RedactPii;
use Throwable;

class RedactMessage
{
    public static function scrub(Throwable $error): string
    {
        return RedactPii::scrub($error::class.': '.$error->getMessage());
    }
}
