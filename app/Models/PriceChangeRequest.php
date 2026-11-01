<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PriceChangeRequest extends Model
{
    protected $fillable = [
        'product_id', 'price', 'currency', 'valid_from', 'customer_type', 'area_id', 'note',
        'status', 'requested_by', 'decided_by', 'decided_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'valid_from' => 'date',
            'decided_at' => 'datetime',
        ];
    }
}
