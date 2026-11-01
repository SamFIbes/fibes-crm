<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class RetentionOffer extends Model
{
    protected $fillable = [
        'ulid', 'retention_case_id', 'kind', 'months', 'percent', 'product_id', 'catalog_price', 'amount', 'status', 'approval_id', 'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'percent' => 'decimal:2',
            'catalog_price' => 'decimal:2',
            'amount' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (RetentionOffer $offer): void {
            $offer->ulid ??= (string) Str::ulid();
        });
    }
}
