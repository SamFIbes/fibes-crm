<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Order extends Model
{
    protected $fillable = [
        'ulid', 'number', 'version', 'customer_id', 'opportunity_id', 'contract_id', 'quote_id',
        'customer_service_id', 'type', 'status', 'paused_status', 'requested_on', 'sla_due', 'area_id',
        'address', 'notes', 'hold_reason', 'cancel_reason', 'currency', 'exchange_rate', 'total',
        'return_charge', 'deposit_paid', 'billing_confirmed', 'approval_id', 'owner_id', 'team_id',
        'created_by', 'updated_by', 'company_id', 'archived_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requested_on' => 'date',
            'sla_due' => 'date',
            'exchange_rate' => 'decimal:4',
            'total' => 'decimal:2',
            'return_charge' => 'decimal:2',
            'deposit_paid' => 'boolean',
            'billing_confirmed' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Order $order): void {
            $order->ulid ??= (string) Str::ulid();
        });

        static::created(function (Order $order): void {
            if ($order->number) {
                return;
            }

            $order->number = 'OR-'.str_pad((string) $order->id, 6, '0', STR_PAD_LEFT);
            $order->saveQuietly();
        });
    }

    /**
     * @return HasMany<OrderLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class)->orderBy('sort');
    }

    /**
     * @return HasMany<Job, $this>
     */
    public function jobs(): HasMany
    {
        return $this->hasMany(Job::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Opportunity, $this>
     */
    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    /**
     * @return BelongsTo<Quote, $this>
     */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }
}
