<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class PurchaseOrder extends Model
{
    protected $fillable = [
        'ulid', 'number', 'supplier_id', 'status', 'erp_ref', 'currency', 'total', 'approval_id',
        'owner_id', 'created_by', 'updated_by', 'archived_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total' => 'decimal:2',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (PurchaseOrder $order): void {
            $order->ulid ??= (string) Str::ulid();
        });

        static::created(function (PurchaseOrder $order): void {
            if ($order->number) {
                return;
            }

            $order->number = 'PO-'.str_pad((string) $order->id, 6, '0', STR_PAD_LEFT);
            $order->saveQuietly();
        });
    }

    /**
     * @return HasMany<PurchaseOrderLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
