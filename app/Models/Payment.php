<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Payment extends Model
{
    protected $fillable = [
        'ulid', 'number', 'customer_id', 'cash_session_id', 'amount', 'tender_amount', 'currency', 'exchange_rate',
        'method', 'reference', 'idempotency_key', 'received_by', 'received_at', 'status', 'approval_id',
        'created_by', 'updated_by', 'version', 'archived_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'tender_amount' => 'decimal:2',
            'exchange_rate' => 'decimal:4',
            'received_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Payment $payment): void {
            $payment->ulid ??= (string) Str::ulid();
        });
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }
}
