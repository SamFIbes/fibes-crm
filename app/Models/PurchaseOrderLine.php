<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderLine extends Model
{
    protected $fillable = [
        'purchase_order_id', 'item_id', 'qty', 'received_qty', 'unit_cost', 'expected_on',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
            'received_qty' => 'decimal:3',
            'unit_cost' => 'decimal:2',
            'expected_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<StockItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'item_id');
    }
}
