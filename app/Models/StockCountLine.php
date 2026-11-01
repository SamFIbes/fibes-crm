<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockCountLine extends Model
{
    protected $fillable = ['stock_count_id', 'item_id', 'expected_qty', 'counted_qty'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expected_qty' => 'decimal:3',
            'counted_qty' => 'decimal:3',
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
