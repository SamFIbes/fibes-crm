<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PipelineStage extends Model
{
    protected $fillable = [
        'pipeline_id', 'entity', 'key', 'name_en', 'name_ar', 'sort', 'probability',
        'is_won', 'is_lost', 'stale_after_days', 'required_fields',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_won' => 'boolean',
            'is_lost' => 'boolean',
            'required_fields' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Pipeline, $this>
     */
    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }
}
