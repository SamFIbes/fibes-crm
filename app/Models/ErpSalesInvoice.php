<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ErpSalesInvoice extends Model
{
    protected $fillable = [
        'erp_name', 'customer_id', 'customer_erp_name', 'posting_date', 'due_date', 'currency',
        'grand_total', 'outstanding_amount', 'status', 'docstatus', 'is_return',
        'content_hash', 'erp_modified', 'last_synced_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'posting_date' => 'date',
            'due_date' => 'date',
            'grand_total' => 'decimal:2',
            'outstanding_amount' => 'decimal:2',
            'is_return' => 'boolean',
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
