<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class WebhookEndpoint extends Model
{
    protected $table = 'webhooks';

    protected $fillable = ['ulid', 'name', 'url', 'secret', 'events', 'enabled', 'created_by'];

    protected $hidden = ['secret'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'events' => 'array',
            'enabled' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (WebhookEndpoint $webhook): void {
            $webhook->ulid ??= (string) Str::ulid();
        });
    }
}
