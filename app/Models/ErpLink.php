<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ErpLink extends Model
{
    protected $fillable = [
        'doctype',
        'erp_name',
        'fibes_type',
        'fibes_id',
        'erp_modified',
        'content_hash',
        'last_synced_at',
        'last_error',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'erp_modified' => 'datetime',
            'last_synced_at' => 'datetime',
            'meta' => 'array',
        ];
    }
}
