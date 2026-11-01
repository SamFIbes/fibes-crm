<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApprovalPolicy extends Model
{
    protected $fillable = [
        'key', 'name_en', 'name_ar', 'conditions', 'steps', 'timeout_hours', 'escalate_to', 'comment_above', 'low_risk', 'pick', 'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'conditions' => 'array',
            'steps' => 'array',
            'comment_above' => 'decimal:2',
            'low_risk' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Approval, $this>
     */
    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class, 'policy_id');
    }
}
