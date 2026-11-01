<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use RuntimeException;

class CommissionEntry extends Model
{
    protected $fillable = [
        'ulid', 'user_id', 'team_id', 'source_type', 'source_id', 'kind', 'amount', 'currency', 'status',
        'period', 'plan_id', 'opportunity_id', 'order_id', 'payment_id', 'customer_service_id', 'approval_id',
        'notes', 'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'period' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CommissionEntry $entry): void {
            $entry->ulid ??= (string) Str::ulid();
        });

        $reject = function (): void {
            throw new RuntimeException('commission_entries is append-only');
        };

        static::updating($reject);
        static::deleting($reject);
    }
}
