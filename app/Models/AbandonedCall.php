<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AbandonedCall extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'call_id', 'caller', 'extension', 'abandoned_at', 'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'abandoned_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
