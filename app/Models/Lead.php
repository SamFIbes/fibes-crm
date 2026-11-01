<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lead extends Model
{
    protected $fillable = [
        'number', 'name', 'first_name', 'last_name', 'phone_e164', 'alt_phone', 'whatsapp_e164', 'email',
        'status', 'source', 'source_id', 'campaign_id', 'referrer_customer_id', 'owner_id', 'team_id',
        'queue', 'service_type_interest', 'plan_interest_id', 'area_id', 'address_text', 'latitude', 'longitude',
        'disqualify_reason_id', 'claimed_at', 'first_response_at', 'sla_started_at', 'sla_due_at',
        'qualify_due_at', 'handoff_due_at', 'sla_stage', 'sla_warned_at', 'sla_breached_at',
        'handed_to_sales_at', 'converted_customer_id', 'converted_opportunity_id', 'duplicate_of_id',
        'brought_by_id', 'no_answer_count', 'language', 'create_reason', 'notes', 'utm', 'custom',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'claimed_at' => 'datetime',
            'first_response_at' => 'datetime',
            'sla_started_at' => 'datetime',
            'sla_due_at' => 'datetime',
            'qualify_due_at' => 'datetime',
            'handoff_due_at' => 'datetime',
            'sla_warned_at' => 'datetime',
            'sla_breached_at' => 'datetime',
            'handed_to_sales_at' => 'datetime',
            'utm' => 'array',
            'custom' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (Lead $lead): void {
            if ($lead->number) {
                return;
            }

            $lead->number = 'LD-'.str_pad((string) $lead->id, 6, '0', STR_PAD_LEFT);
            $lead->saveQuietly();
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsTo<LeadSource, $this>
     */
    public function sourceRow(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class, 'source_id');
    }

    /**
     * @return HasMany<LeadActivity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(LeadActivity::class);
    }

    public function displayName(): string
    {
        $name = trim((string) $this->name);

        if ($name !== '') {
            return $name;
        }

        return trim(((string) $this->first_name).' '.((string) $this->last_name));
    }
}
