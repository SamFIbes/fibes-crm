<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OnboardingCard extends Model
{
    protected $fillable = [
        'title', 'area_id', 'client_name', 'client_phone', 'client_email', 'language',
        'source_kind', 'source_id', 'salesperson_id', 'salesperson_name', 'stage_key',
        'stage_entered_at', 'last_activity_at', 'assigned_to', 'lead_id', 'customer_id',
    ];

    protected function casts(): array
    {
        return [
            'stage_entered_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Area, $this> */
    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    /** @return BelongsTo<User, $this> */
    public function salesperson(): BelongsTo
    {
        return $this->belongsTo(User::class, 'salesperson_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return BelongsTo<Lead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return HasMany<OnboardingEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(OnboardingEvent::class, 'card_id');
    }

    /** @return HasOne<OnboardingFollowup, $this> */
    public function followup(): HasOne
    {
        return $this->hasOne(OnboardingFollowup::class, 'card_id');
    }
}
