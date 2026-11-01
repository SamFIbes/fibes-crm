<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockCount extends Model
{
    protected $fillable = ['location_id', 'user_id', 'note', 'applied_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['applied_at' => 'datetime'];
    }

    /**
     * @return HasMany<StockCountLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(StockCountLine::class);
    }
}
