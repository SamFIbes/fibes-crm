<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnboardingFollowup extends Model
{
    protected $primaryKey = 'card_id';

    public $incrementing = false;

    protected $fillable = ['card_id', 'due_on', 'note', 'set_by', 'set_at'];

    protected function casts(): array
    {
        return ['due_on' => 'date', 'set_at' => 'datetime'];
    }

    /** @return BelongsTo<OnboardingCard, $this> */
    public function card(): BelongsTo
    {
        return $this->belongsTo(OnboardingCard::class, 'card_id');
    }

    /** @return BelongsTo<User, $this> */
    public function setter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by');
    }
}
