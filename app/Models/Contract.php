<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use RuntimeException;

class Contract extends Model
{
    protected $fillable = [
        'ulid', 'number', 'customer_id', 'opportunity_id', 'quote_id', 'template_id', 'version', 'status',
        'term_months', 'start_on', 'end_on', 'auto_renew', 'early_termination_fee', 'currency', 'body_html',
        'snapshot_html', 'signed_at', 'signer_name', 'signer_phone', 'signer_ip', 'signer_user_agent',
        'signature_attachment_id', 'signed_pdf_attachment_id', 'sha256', 'public_token', 'owner_id',
        'created_by', 'updated_by', 'company_id', 'archived_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'auto_renew' => 'boolean',
            'early_termination_fee' => 'decimal:2',
            'start_on' => 'date',
            'end_on' => 'date',
            'signed_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Contract $contract): void {
            $contract->ulid ??= (string) Str::ulid();
            $contract->public_token ??= Str::random(40);
        });

        static::created(function (Contract $contract): void {
            if ($contract->number) {
                return;
            }

            $contract->number = 'CT-'.str_pad((string) $contract->id, 6, '0', STR_PAD_LEFT);
            $contract->saveQuietly();
        });

        static::updating(function (Contract $contract): void {
            if ($contract->getOriginal('status') === 'signed') {
                throw new RuntimeException('A signed contract cannot be changed.');
            }
        });
    }

    /**
     * @return BelongsTo<Quote, $this>
     */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    /**
     * @return BelongsTo<DocumentTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class, 'template_id');
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
