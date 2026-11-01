<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Product extends Model
{
    protected $fillable = [
        'sku', 'kind', 'service_type', 'name_en', 'name_ar', 'offering', 'cost_usd', 'stock_item_id', 'is_active', 'archived_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cost_usd' => 'decimal:2',
            'is_active' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @return HasOne<Plan, $this>
     */
    public function plan(): HasOne
    {
        return $this->hasOne(Plan::class);
    }

    /**
     * @return HasMany<PriceListItem, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(PriceListItem::class);
    }

    /**
     * @return BelongsTo<StockItem, $this>
     */
    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }
}
