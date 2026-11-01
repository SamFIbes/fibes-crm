<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerView extends Model
{
    protected $fillable = ['user_id', 'name', 'filters'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['filters' => 'array'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
