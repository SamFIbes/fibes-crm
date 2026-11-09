<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ErpPaymentEntry extends Model
{
    protected $fillable = [
        'erp_name', 'customer_id', 'customer_erp_name', 'posting_date', 'paid_amount', 'received_amount',
        'mode_of_payment', 'status', 'docstatus', 'party_type',
        'content_hash', 'erp_modified', 'last_synced_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'posting_date' => 'date',
            'paid_amount' => 'decimal:2',
            'received_amount' => 'decimal:2',
            'erp_modified' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
