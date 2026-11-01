<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobCheck extends Model
{
    protected $fillable = ['job_id', 'label_en', 'label_ar', 'required', 'done', 'sort'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'required' => 'boolean',
            'done' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Job, $this>
     */
    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }
}
