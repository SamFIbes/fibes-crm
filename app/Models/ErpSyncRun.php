<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ErpSyncRun extends Model
{
    protected $fillable = [
        'mode',
        'status',
        'scanned',
        'would_create',
        'would_update',
        'unchanged',
        'ambiguous',
        'errors',
        'contacts',
        'addresses',
        'hub_contact_count',
        'hub_address_count',
        'cursor',
        'message',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'scanned' => 'integer',
            'would_create' => 'integer',
            'would_update' => 'integer',
            'unchanged' => 'integer',
            'ambiguous' => 'integer',
            'errors' => 'integer',
            'contacts' => 'integer',
            'addresses' => 'integer',
            'hub_contact_count' => 'integer',
            'hub_address_count' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
