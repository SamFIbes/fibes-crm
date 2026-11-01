<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class QaEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['qa_evaluation_id', 'from_status', 'to_status', 'actor_id'];

    protected static function booted(): void
    {
        $reject = function (): void {
            throw new RuntimeException('qa_events is append-only');
        };

        static::updating($reject);
        static::deleting($reject);
    }
}
