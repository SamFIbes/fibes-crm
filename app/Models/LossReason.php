<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LossReason extends Model
{
    protected $fillable = ['key', 'name_en', 'name_ar', 'win_back'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['win_back' => 'boolean'];
    }
}
