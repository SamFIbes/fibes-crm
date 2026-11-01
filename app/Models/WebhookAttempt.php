<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class WebhookAttempt extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['webhook_delivery_id', 'attempt', 'status_code', 'result'];

    protected static function booted(): void
    {
        $reject = function (): void {
            throw new RuntimeException('webhook_attempts is append-only');
        };

        static::updating($reject);
        static::deleting($reject);
    }
}
