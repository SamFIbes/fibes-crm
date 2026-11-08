<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model
{
    protected $fillable = [
        'number', 'title', 'amount_usd', 'status', 'customer_id', 'network_node_id',
        'job_id', 'erp_name', 'notes', 'actor_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_usd' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (Expense $expense): void {
            if ($expense->number) {
                return;
            }

            $expense->number = 'EX-'.str_pad((string) $expense->id, 6, '0', STR_PAD_LEFT);
            $expense->saveQuietly();
        });
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<NetworkNode, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(NetworkNode::class, 'network_node_id');
    }

    /**
     * @return BelongsTo<Job, $this>
     */
    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'job_id');
    }
}
