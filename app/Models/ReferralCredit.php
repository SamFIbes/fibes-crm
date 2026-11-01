<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReferralCredit extends Model
{
    protected $fillable = ['lead_id', 'customer_id', 'amount', 'currency', 'status', 'needs_approval'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'needs_approval' => 'boolean',
        ];
    }
}
