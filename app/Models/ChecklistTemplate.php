<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChecklistTemplate extends Model
{
    protected $fillable = ['kind', 'job_type', 'ticket_category_id', 'label_en', 'label_ar', 'required', 'sort'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'required' => 'boolean',
        ];
    }
}
