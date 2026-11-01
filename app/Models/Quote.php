<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Quote extends Model
{
    protected $fillable = [
        'ulid', 'number', 'version', 'parent_id', 'opportunity_id', 'customer_id', 'customer_name',
        'customer_email', 'customer_phone', 'owner_id', 'team_id', 'status', 'language', 'currency',
        'exchange_rate', 'valid_until', 'subtotal', 'discount_total', 'tax_total', 'total', 'apply_vat',
        'promo_code', 'approval_id', 'pdf_attachment_id', 'approver_name', 'approved_by', 'public_token',
        'sent_at', 'accepted_at', 'rejected_at', 'created_by', 'updated_by', 'company_id', 'archived_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'exchange_rate' => 'decimal:4',
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'total' => 'decimal:2',
            'apply_vat' => 'boolean',
            'valid_until' => 'date',
            'sent_at' => 'datetime',
            'accepted_at' => 'datetime',
            'rejected_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Quote $quote): void {
            $quote->ulid ??= (string) Str::ulid();
            $quote->public_token ??= Str::random(40);
        });

        static::created(function (Quote $quote): void {
            if ($quote->number) {
                return;
            }

            $quote->number = 'QT-'.str_pad((string) $quote->id, 6, '0', STR_PAD_LEFT);
            $quote->saveQuietly();
        });
    }

    /**
     * @return HasMany<QuoteLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(QuoteLine::class)->orderBy('sort');
    }

    /**
     * @return BelongsTo<Opportunity, $this>
     */
    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return HasMany<Contract, $this>
     */
    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }
}
