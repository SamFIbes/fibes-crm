<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeadSource extends Model
{
    protected $fillable = ['channel', 'name_en', 'name_ar', 'active'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
