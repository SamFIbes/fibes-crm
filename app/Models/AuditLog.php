<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class AuditLog extends Model
{
    protected $table = 'audit_log';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'ulid',
        'occurred_at',
        'actor_id',
        'event',
        'ip_address',
        'user_agent',
        'metadata',
        'customer_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        $reject = function (): void {
            throw new RuntimeException('audit_log is append-only');
        };

        static::updating($reject);
        static::deleting($reject);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
