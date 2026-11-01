<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Target extends Model
{
    protected $fillable = [
        'ulid', 'period', 'subject_type', 'subject_id', 'subject_key', 'metric', 'value',
        'period_start', 'currency', 'created_by', 'updated_by', 'version', 'archived_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'period_start' => 'date',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Target $target): void {
            $target->ulid ??= (string) Str::ulid();
        });
    }
}
