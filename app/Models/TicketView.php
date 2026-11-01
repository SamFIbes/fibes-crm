<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TicketView extends Model
{
    protected $fillable = ['user_id', 'name', 'filters'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['filters' => 'array'];
    }
}
