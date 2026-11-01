<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class RetentionCase extends Model
{
    protected $fillable = [
        'ulid', 'customer_id', 'order_id', 'subscription_id', 'stage', 'reason', 'outcome', 'assigned_to',
        'opened_on', 'created_by', 'updated_by', 'version', 'archived_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'opened_on' => 'date',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (RetentionCase $case): void {
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

    /**
     * @return HasMany<RetentionOffer, $this>
     */
    public function offers(): HasMany
    {
        return $this->hasMany(RetentionOffer::class);
    }
}
