<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketEscalation extends Model
{
    protected $fillable = [
        'ticket_id', 'direction', 'from_tier_id', 'to_tier_id', 'from_assignee_id', 'to_assignee_id',
        'reason', 'handover_note', 'automatic', 'trigger', 'actor_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'automatic' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * @return BelongsTo<EscalationTier, $this>
     */
    public function fromTier(): BelongsTo
    {
        return $this->belongsTo(EscalationTier::class, 'from_tier_id');
    }

    /**
     * @return BelongsTo<EscalationTier, $this>
     */
    public function toTier(): BelongsTo
    {
        return $this->belongsTo(EscalationTier::class, 'to_tier_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
