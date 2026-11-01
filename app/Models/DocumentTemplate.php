<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class DocumentTemplate extends Model
{
    protected $fillable = [
        'ulid', 'type', 'locale', 'version', 'name', 'body', 'is_active', 'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (DocumentTemplate $template): void {
            $template->ulid ??= (string) Str::ulid();
        });
    }
}
