<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Contact extends Model
{
    protected $fillable = ['customer_id', 'name', 'phone_e164', 'is_primary'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }
}
