<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Plan extends Model
{
    protected $fillable = [
        'product_id', 'down_mbps', 'up_mbps', 'burst_mbps', 'fup_gb', 'billing_cycle',
        'radius_profile_name', 'needs_survey', 'min_term_months', 'is_public',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'needs_survey' => 'boolean',
            'is_public' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
