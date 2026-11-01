<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockItem extends Model
{
    protected $fillable = [
        'sku', 'name_en', 'name_ar', 'unit', 'serialized', 'reorder_level', 'unit_cost_usd', 'low_stock_alerted',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'serialized' => 'boolean',
            'reorder_level' => 'decimal:3',
            'unit_cost_usd' => 'decimal:2',
            'low_stock_alerted' => 'boolean',
        ];
    }
}
