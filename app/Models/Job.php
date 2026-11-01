<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Job extends Model
{
    protected $table = 'field_jobs';

    protected $fillable = [
        'number', 'type', 'status', 'customer_id', 'customer_service_id', 'ticket_id', 'team_id',
        'technician_id', 'required_skill', 'window_start', 'window_end', 'failure_reason', 'failure_note',
        'notes', 'materials', 'signed_by', 'signature_attachment_id', 'owner_id', 'opportunity_id', 'order_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'window_start' => 'datetime',
            'window_end' => 'datetime',
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
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    /**
     * @return HasOne<SurveyResult, $this>
     */
    public function survey(): HasOne
    {
        return $this->hasOne(SurveyResult::class, 'job_id');
    }

    /**
     * @return HasMany<JobCheck, $this>
     */
    public function checks(): HasMany
    {
        return $this->hasMany(JobCheck::class);
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function files(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
