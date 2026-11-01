<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockReservation extends Model
{
    protected $fillable = ['job_id', 'item_id', 'serial_id', 'location_id', 'quantity', 'status'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['quantity' => 'decimal:3'];
    }
}
