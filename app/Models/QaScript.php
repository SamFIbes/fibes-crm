<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class QaScript extends Model
{
    protected $fillable = ['ulid', 'call_type', 'version', 'name_en', 'name_ar', 'steps', 'is_current'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'steps' => 'array',
            'is_current' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (QaScript $script): void {
            $script->ulid ??= (string) Str::ulid();
        });
    }
}
