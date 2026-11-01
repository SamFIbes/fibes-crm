<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CoverageItem extends Model
{
    protected $fillable = [
        'service_type', 'kind', 'name', 'latitude', 'longitude', 'polygon', 'azimuth', 'beamwidth', 'range_m',
        'capacity_total', 'capacity_used', 'status', 'source', 'import_batch_id', 'network_node_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'polygon' => 'array',
            'azimuth' => 'float',
            'beamwidth' => 'float',
            'range_m' => 'float',
        ];
    }

    public function freePorts(): int
    {
        return max(0, (int) $this->capacity_total - (int) $this->capacity_used);
    }

    public function isFull(): bool
    {
        return $this->status === 'full' || $this->freePorts() < 1;
    }
}
