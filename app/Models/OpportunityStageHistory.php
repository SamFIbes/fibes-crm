<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class OpportunityStageHistory extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'opportunity_stage_history';

    protected $fillable = ['opportunity_id', 'stage_id', 'entered_at', 'left_at', 'seconds'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entered_at' => 'datetime',
            'left_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PipelineStage, $this>
     */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'stage_id');
    }

    protected static function booted(): void
    {
        $reject = function (): void {
            throw new RuntimeException('opportunity_stage_history is append-only');
        };

        static::updating($reject);
        static::deleting($reject);
    }
}
