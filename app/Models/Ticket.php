<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Ticket extends Model
{
    protected $fillable = [
        'number', 'customer_id', 'customer_service_id', 'category_id', 'priority', 'channel', 'status',
        'subject', 'assignee_id', 'team_id', 'owner_id', 'escalation_tier_id', 'incident_id', 'escalation_reason', 'handover_note',
        'response_minutes', 'resolve_minutes', 'response_due_at', 'resolve_due_at', 'first_response_at',
        'paused_at', 'paused_seconds', 'response_warned', 'response_breached', 'resolve_warned', 'resolve_breached',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'response_due_at' => 'datetime',
            'resolve_due_at' => 'datetime',
            'first_response_at' => 'datetime',
            'paused_at' => 'datetime',
            'response_warned' => 'boolean',
            'response_breached' => 'boolean',
            'resolve_warned' => 'boolean',
            'resolve_breached' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<CustomerService, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(CustomerService::class, 'customer_service_id');
    }

    /**
     * @return BelongsTo<TicketCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(TicketCategory::class, 'category_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsTo<EscalationTier, $this>
     */
    public function tier(): BelongsTo
    {
        return $this->belongsTo(EscalationTier::class, 'escalation_tier_id');
    }

    /**
     * @return BelongsTo<Incident, $this>
     */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    /**
     * @return HasMany<TicketEscalation, $this>
     */
    public function escalations(): HasMany
    {
        return $this->hasMany(TicketEscalation::class);
    }

    /**
     * @return HasMany<TicketCheck, $this>
     */
    public function checks(): HasMany
    {
        return $this->hasMany(TicketCheck::class);
    }

    /**
     * @return HasOne<CsatResponse, $this>
     */
    public function csat(): HasOne
    {
        return $this->hasOne(CsatResponse::class);
    }

    /**
     * @return HasMany<WorkTask, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(WorkTask::class);
    }

    /**
     * @return HasMany<TicketMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class);
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function files(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
