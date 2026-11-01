<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pipeline extends Model
{
    protected $fillable = ['key', 'name_en', 'name_ar', 'service_types'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['service_types' => 'array'];
    }

    /**
     * @return HasMany<PipelineStage, $this>
     */
    public function stages(): HasMany
    {
        return $this->hasMany(PipelineStage::class)->orderBy('sort');
    }
}
