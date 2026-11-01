<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class ApprovalDecision extends Model
{
    protected $fillable = ['approval_id', 'step', 'approver_id', 'decision', 'comment', 'decided_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        $reject = function (): void {
            throw new RuntimeException('approval_decisions is append-only');
        };

        static::updating($reject);
        static::deleting($reject);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
}
