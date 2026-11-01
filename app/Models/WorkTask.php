<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkTask extends Model
{
    protected $table = 'tasks';

    protected $fillable = [
        'kind', 'ticket_id', 'job_id', 'lead_id', 'opportunity_id', 'customer_id', 'assignee_id', 'team_id', 'owner_id',
        'due_at', 'remind_at', 'reminded', 'overdue_notified', 'rule_id', 'occurrence_on',
        'status', 'priority', 'title', 'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'remind_at' => 'datetime',
            'occurrence_on' => 'date',
            'reminded' => 'boolean',
            'overdue_notified' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function isOverdue(): bool
    {
        return $this->status === 'open' && $this->due_at !== null && $this->due_at->isPast();
    }
}
