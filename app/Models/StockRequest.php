<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockRequest extends Model
{
    protected $fillable = [
        'number', 'status', 'purpose', 'item_id', 'location_id', 'quantity', 'warehouse',
        'required_by', 'customer_id', 'network_node_id', 'job_id', 'expense_id',
        'erp_name', 'remarks', 'last_error', 'requested_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'required_by' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (StockRequest $request): void {
            if ($request->number) {
                return;
            }

            $request->number = 'SR-'.str_pad((string) $request->id, 6, '0', STR_PAD_LEFT);
            $request->saveQuietly();
        });
    }

    /**
     * @return BelongsTo<StockItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'item_id');
    }

    /**
     * @return BelongsTo<StockLocation, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'location_id');
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<NetworkNode, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(NetworkNode::class, 'network_node_id');
    }

    /**
     * @return BelongsTo<Job, $this>
     */
    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'job_id');
    }

    /**
     * @return BelongsTo<Expense, $this>
     */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }
}
