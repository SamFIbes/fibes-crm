<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnboardingEvent extends Model
{
    protected $fillable = [
        'card_id', 'occurred_at', 'actor_id', 'actor_name', 'kind', 'from_stage', 'to_stage', 'detail',
    ];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    /** @return BelongsTo<OnboardingCard, $this> */
    public function card(): BelongsTo
    {
        return $this->belongsTo(OnboardingCard::class, 'card_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
