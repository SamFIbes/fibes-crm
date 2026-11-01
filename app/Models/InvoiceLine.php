<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceLine extends Model
{
    protected $fillable = [
        'invoice_id', 'product_id', 'description', 'qty', 'unit_price', 'tax_rate', 'line_total', 'is_recurring', 'sort',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'qty' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'line_total' => 'decimal:2',
            'is_recurring' => 'boolean',
        ];
    }
}
