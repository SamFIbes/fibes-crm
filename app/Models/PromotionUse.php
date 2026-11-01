<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class PromotionUse extends Model
{
    protected $fillable = ['promotion_id', 'customer_id', 'used_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'used_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        $reject = function (): void {
            throw new RuntimeException('promotion_uses is append-only');
        };

        static::updating($reject);
        static::deleting($reject);
    }
}
