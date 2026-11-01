<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskRule extends Model
{
    protected $fillable = [
        'title', 'notes', 'priority', 'frequency', 'weekday', 'day_of_month', 'time_of_day',
        'next_run_at', 'active', 'assignee_id', 'team_id', 'customer_id', 'owner_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'next_run_at' => 'datetime',
            'active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }
}
