<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ApiKey extends Model
{
    protected $fillable = ['ulid', 'name', 'token_hash', 'scopes', 'last_used_at', 'expires_at', 'created_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ApiKey $key): void {
            $key->ulid ??= (string) Str::ulid();
        });
    }
}
