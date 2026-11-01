<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Customer extends Model
{
    protected $fillable = [
        'account_no', 'type', 'status', 'name_en', 'name_ar', 'company_name', 'email',
        'whatsapp_e164', 'area_id', 'building', 'floor', 'landmark', 'latitude', 'longitude',
        'tags', 'notes', 'owner_id', 'team_id', 'whatsapp_consent',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'whatsapp_consent' => 'boolean',
        ];
    }

    /**
     * @return HasMany<CustomerPhone, $this>
     */
    public function phones(): HasMany
    {
        return $this->hasMany(CustomerPhone::class);
    }

    /**
     * @return HasMany<CustomerService, $this>
     */
    public function services(): HasMany
    {
        return $this->hasMany(CustomerService::class);
    }

    /**
     * @return HasMany<CustomerNote, $this>
     */
    /**
     * @return MorphMany<Attachment, $this>
     */
    public function files(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /**
     * @return HasMany<CustomerNote, $this>
     */
    public function customerNotes(): HasMany
    {
        return $this->hasMany(CustomerNote::class);
    }

    /**
     * @return BelongsTo<Area, $this>
     */
    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
