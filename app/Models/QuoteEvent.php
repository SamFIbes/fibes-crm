<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class QuoteEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['quote_id', 'from_status', 'to_status', 'actor_id'];

    protected static function booted(): void
    {
        $reject = function (): void {
            throw new RuntimeException('quote_events is append-only');
        };

        static::updating($reject);
        static::deleting($reject);
    }
}
