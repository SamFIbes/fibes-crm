<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppEvent extends Model
{
    protected $table = 'whatsapp_events';

    public $timestamps = false;

    protected $fillable = [
        'provider_id', 'status', 'recipient', 'payload', 'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
