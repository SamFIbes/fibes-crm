<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookDelivery extends Model
{
    protected $fillable = [
        'webhook_id', 'event', 'payload', 'status', 'attempts', 'next_attempt_at', 'last_error', 'delivered_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'next_attempt_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<WebhookEndpoint, $this>
     */
    public function webhook(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_id');
    }
}
