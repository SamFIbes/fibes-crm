<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SurveyResult extends Model
{
    protected $fillable = [
        'job_id', 'feasible', 'reason', 'distance_m', 'los', 'signal_dbm', 'cable_m',
        'mounting_point', 'riser_ok', 'power_ok', 'materials', 'recommended_service_type', 'photos',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'feasible' => 'boolean',
            'riser_ok' => 'boolean',
            'power_ok' => 'boolean',
            'materials' => 'array',
            'photos' => 'array',
            'distance_m' => 'float',
            'signal_dbm' => 'float',
            'cable_m' => 'float',
        ];
    }

    /**
     * @return BelongsTo<Job, $this>
     */
    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'job_id');
    }
}
