<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class LedgerEntry extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'customer_id', 'entry_type', 'debit', 'credit', 'currency', 'exchange_rate', 'source_type', 'source_id', 'balance_after', 'actor_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'debit' => 'decimal:2',
            'credit' => 'decimal:2',
            'exchange_rate' => 'decimal:4',
            'balance_after' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        $reject = function (): void {
            throw new RuntimeException('ledger_entries is append-only');
        };

        static::updating($reject);
        static::deleting($reject);
    }
}
