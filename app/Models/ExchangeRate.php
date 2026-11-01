<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class ExchangeRate extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['effective_on', 'usd_lbp', 'confirmed', 'note', 'set_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effective_on' => 'date',
            'usd_lbp' => 'decimal:4',
            'confirmed' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        $reject = function (): void {
            throw new RuntimeException('exchange_rates is append-only');
        };

        static::updating($reject);
        static::deleting($reject);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by');
    }
}
