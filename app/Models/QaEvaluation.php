<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class QaEvaluation extends Model
{
    protected $fillable = [
        'ulid', 'subject_type', 'subject_id', 'call_id', 'agent_id', 'evaluator_id', 'form_id', 'form_version',
        'scores', 'total_pct', 'critical_fail', 'coaching_notes', 'status', 'acknowledged_at', 'created_by', 'updated_by', 'version', 'archived_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scores' => 'array',
            'total_pct' => 'decimal:2',
            'critical_fail' => 'boolean',
            'acknowledged_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (QaEvaluation $evaluation): void {
            $evaluation->ulid ??= (string) Str::ulid();
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }
}
