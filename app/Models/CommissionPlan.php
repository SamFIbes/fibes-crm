<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CommissionPlan extends Model
{
    protected $fillable = ['ulid', 'version', 'rules', 'valid_from', 'valid_to', 'is_current', 'created_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rules' => 'array',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'is_current' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CommissionPlan $plan): void {
            $plan->ulid ??= (string) Str::ulid();
        });
    }
}
