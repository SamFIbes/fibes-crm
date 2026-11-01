<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Opportunity extends Model
{
    protected $fillable = [
        'number', 'customer_id', 'contact_id', 'lead_id', 'pipeline_id', 'stage_id', 'owner_id', 'team_id',
        'area_id', 'service_type', 'plan_id', 'coverage_check_id', 'coverage_note', 'mrc', 'nrc', 'currency',
        'probability', 'expected_close', 'stage_entered_at', 'won_at', 'lost_at', 'loss_reason_id',
        'competitor', 'override_reason', 'management_confirmed',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mrc' => 'decimal:2',
            'nrc' => 'decimal:2',
            'expected_close' => 'date',
            'stage_entered_at' => 'datetime',
            'won_at' => 'datetime',
            'lost_at' => 'datetime',
            'management_confirmed' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (Opportunity $opportunity): void {
            if ($opportunity->number) {
                return;
            }

            $opportunity->number = 'OP-'.str_pad((string) $opportunity->id, 6, '0', STR_PAD_LEFT);
            $opportunity->saveQuietly();
        });
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Pipeline, $this>
     */
    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }

    /**
     * @return BelongsTo<PipelineStage, $this>
     */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'stage_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsTo<LossReason, $this>
     */
    public function lossReason(): BelongsTo
    {
        return $this->belongsTo(LossReason::class, 'loss_reason_id');
    }

    /**
     * @return HasMany<OpportunityStageHistory, $this>
     */
    public function history(): HasMany
    {
        return $this->hasMany(OpportunityStageHistory::class);
    }

    /**
     * @return HasMany<WorkTask, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(WorkTask::class);
    }
}
