<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CashSession extends Model
{
    protected $fillable = [
        'ulid', 'user_id', 'kind', 'status', 'opened_at', 'closed_at', 'opening', 'expected', 'counted', 'variance',
        'approved_by', 'approval_id', 'created_by', 'updated_by', 'version', 'archived_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening' => 'decimal:2',
            'expected' => 'decimal:2',
            'counted' => 'decimal:2',
            'variance' => 'decimal:2',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CashSession $session): void {
            $session->ulid ??= (string) Str::ulid();
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
