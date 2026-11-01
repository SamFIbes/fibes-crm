<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DisqualifyReason extends Model
{
    protected $fillable = ['key', 'name_en', 'name_ar', 'active'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
