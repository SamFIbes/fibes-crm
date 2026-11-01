<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class StockMovement extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'item_id', 'serial_id', 'from_location_id', 'to_location_id', 'quantity', 'unit_cost_usd',
        'kind', 'reason', 'job_id', 'customer_service_id', 'actor_id', 'approved_by', 'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_cost_usd' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<StockItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'item_id');
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException('stock_movements is append-only');
        });
        static::deleting(function (): void {
            throw new RuntimeException('stock_movements is append-only');
        });
    }
}
