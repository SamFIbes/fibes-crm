<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CollectionCase extends Model
{
    protected $fillable = [
        'ulid', 'customer_id', 'subscription_id', 'invoice_id', 'amount_due', 'stage', 'assigned_to',
        'promise_to_pay_date', 'promise_amount', 'outcome', 'opened_on', 'created_by', 'updated_by', 'version', 'archived_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_due' => 'decimal:2',
            'promise_amount' => 'decimal:2',
            'promise_to_pay_date' => 'date',
            'opened_on' => 'date',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CollectionCase $case): void {
            $case->ulid ??= (string) Str::ulid();
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
