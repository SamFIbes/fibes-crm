<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Invoice extends Model
{
    protected $fillable = [
        'ulid', 'number', 'customer_id', 'subscription_id', 'order_id', 'type', 'period_start', 'period_end',
        'currency', 'exchange_rate', 'subtotal', 'discount', 'tax', 'total', 'lbp_total', 'status', 'due_at',
        'issued_at', 'voided_at', 'void_reason', 'pdf_attachment_id', 'created_by', 'version', 'archived_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'exchange_rate' => 'decimal:4',
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'tax' => 'decimal:2',
            'total' => 'decimal:2',
            'lbp_total' => 'decimal:2',
            'due_at' => 'datetime',
            'issued_at' => 'datetime',
            'voided_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Invoice $invoice): void {
            $invoice->ulid ??= (string) Str::ulid();
        });
    }

    /**
     * @return HasMany<InvoiceLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('sort');
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
