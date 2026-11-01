<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Interaction extends Model
{
    protected $fillable = [
        'direction', 'channel', 'wrap_up_code_id', 'duration_seconds', 'notes', 'phone_e164',
        'customer_id', 'ticket_id', 'lead_id', 'user_id', 'callback_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'callback_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<WrapUpCode, $this>
     */
    public function wrapUpCode(): BelongsTo
    {
        return $this->belongsTo(WrapUpCode::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
