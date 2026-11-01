<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CoverageCheck extends Model
{
    protected $fillable = [
        'subject_type', 'subject_id', 'latitude', 'longitude', 'address_text', 'result',
        'matched_items', 'distance_m', 'checked_by', 'checked_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'matched_items' => 'array',
            'distance_m' => 'float',
            'checked_at' => 'datetime',
        ];
    }
}
