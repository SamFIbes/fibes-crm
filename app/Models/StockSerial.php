<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockSerial extends Model
{
    protected $fillable = [
        'item_id', 'serial', 'mac', 'status', 'location_id', 'customer_service_id', 'job_id',
    ];

    /**
     * @return BelongsTo<StockItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'item_id');
    }
}
