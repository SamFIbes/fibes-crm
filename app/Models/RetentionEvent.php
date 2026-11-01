<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class RetentionEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['retention_case_id', 'from_stage', 'to_stage', 'actor_id'];

    protected static function booted(): void
    {
        $reject = function (): void {
            throw new RuntimeException('retention_events is append-only');
        };

        static::updating($reject);
        static::deleting($reject);
    }
}
