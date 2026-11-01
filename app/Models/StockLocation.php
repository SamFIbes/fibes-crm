<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockLocation extends Model
{
    protected $fillable = ['kind', 'name', 'user_id', 'is_active'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function keeper(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
