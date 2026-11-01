<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Automation extends Model
{
    protected $fillable = [
        'ulid', 'key', 'version', 'name_en', 'name_ar', 'trigger', 'conditions', 'actions',
        'enabled', 'is_current', 'rate_per_hour', 'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'conditions' => 'array',
            'actions' => 'array',
            'enabled' => 'boolean',
            'is_current' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Automation $automation): void {
            $automation->ulid ??= (string) Str::ulid();
        });
    }
}
