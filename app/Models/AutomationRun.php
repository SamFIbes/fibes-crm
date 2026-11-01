<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class AutomationRun extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'automation_id', 'trigger', 'status', 'dry_run', 'subject_type', 'subject_id', 'depth', 'message',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['dry_run' => 'boolean'];
    }

    protected static function booted(): void
    {
        $reject = function (): void {
            throw new RuntimeException('automation_runs is append-only');
        };

        static::updating($reject);
        static::deleting($reject);
    }
}
