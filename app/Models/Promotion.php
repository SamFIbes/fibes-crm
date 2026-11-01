<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Promotion extends Model
{
    protected $fillable = [
        'code', 'name_en', 'name_ar', 'type', 'value', 'applies_to', 'valid_from', 'valid_to',
        'max_uses', 'per_customer_limit', 'stackable', 'requires_approval', 'min_term_months', 'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'applies_to' => 'array',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'stackable' => 'boolean',
            'requires_approval' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function requiresApproval(): bool
    {
        return $this->requires_approval;
    }

    /**
     * @return HasMany<PromotionUse, $this>
     */
    public function uses(): HasMany
    {
        return $this->hasMany(PromotionUse::class);
    }
}
