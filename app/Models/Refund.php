<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Refund extends Model
{
    protected $fillable = ['ulid', 'customer_id', 'payment_id', 'approval_id', 'amount', 'currency', 'status', 'created_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        static::creating(function (Refund $refund): void {
            $refund->ulid ??= (string) Str::ulid();
        });
    }
}
