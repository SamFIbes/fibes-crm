<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DashboardLayout extends Model
{
    protected $fillable = ['user_id', 'keys'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['keys' => 'array'];
    }
}
