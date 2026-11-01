<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Approval extends Model
{
    protected $fillable = [
        'policy_id', 'type', 'subject_type', 'subject_id', 'requester_id', 'status', 'subject_state',
        'current_step', 'steps', 'payload', 'summary', 'due_at', 'reminded_at', 'escalated_at', 'decided_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'steps' => 'array',
            'payload' => 'array',
            'due_at' => 'datetime',
            'reminded_at' => 'datetime',
            'escalated_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ApprovalPolicy, $this>
     */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(ApprovalPolicy::class, 'policy_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    /**
     * @return HasMany<ApprovalDecision, $this>
     */
    public function decisions(): HasMany
    {
        return $this->hasMany(ApprovalDecision::class);
    }
}
