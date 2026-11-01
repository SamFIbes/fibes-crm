<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class WriteOff extends Model
{
    protected $fillable = ['ulid', 'invoice_id', 'approval_id', 'amount', 'status', 'created_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        static::creating(function (WriteOff $writeOff): void {
            $writeOff->ulid ??= (string) Str::ulid();
        });
    }
}
