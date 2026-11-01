<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class DealEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'kind', 'opportunity_id', 'order_id', 'owner_id', 'team_id', 'amount', 'currency',
        'is_big_deal', 'fanfare', 'label',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'is_big_deal' => 'boolean',
            'fanfare' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        $reject = function (): void {
            throw new RuntimeException('deal_events is append-only');
        };

        static::updating($reject);
        static::deleting($reject);
    }
}
