<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Holiday extends Model
{
    protected $fillable = ['observed_on', 'name_en', 'name_ar', 'kind', 'confirmed'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'observed_on' => 'date',
            'confirmed' => 'boolean',
        ];
    }
}
