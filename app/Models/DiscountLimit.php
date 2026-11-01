<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DiscountLimit extends Model
{
    protected $fillable = [
        'role', 'line_percent', 'line_amount', 'total_percent', 'total_amount', 'currency',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'line_percent' => 'decimal:2',
            'line_amount' => 'decimal:2',
            'total_percent' => 'decimal:2',
            'total_amount' => 'decimal:2',
        ];
    }
}
