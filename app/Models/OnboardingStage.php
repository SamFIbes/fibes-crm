<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OnboardingStage extends Model
{
    protected $fillable = [
        'key', 'name_en', 'name_ar', 'position', 'task_title_en', 'task_title_ar', 'is_terminal',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_terminal' => 'boolean',
        ];
    }
}
