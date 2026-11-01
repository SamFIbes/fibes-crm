<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Subscription extends Model
{
    protected $fillable = [
        'ulid', 'customer_id', 'service_id', 'plan_id', 'order_id', 'contract_id', 'price', 'currency',
        'billing_cycle', 'prepaid', 'current_period_start', 'current_period_end', 'next_invoice_at', 'status',
        'auto_renew', 'discount_id', 'promo_id', 'grace_days', 'created_by', 'updated_by', 'version', 'archived_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'prepaid' => 'boolean',
            'auto_renew' => 'boolean',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'next_invoice_at' => 'date',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Subscription $subscription): void {
            $subscription->ulid ??= (string) Str::ulid();
        });
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
