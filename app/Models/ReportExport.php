<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ReportExport extends Model
{
    protected $fillable = ['ulid', 'user_id', 'report_key', 'format', 'status', 'path', 'rows', 'expires_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (ReportExport $export): void {
            $export->ulid ??= (string) Str::ulid();
        });
    }
}
