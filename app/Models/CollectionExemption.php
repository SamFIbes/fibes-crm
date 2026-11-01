<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CollectionExemption extends Model
{
    protected $fillable = ['customer_id', 'kind', 'until', 'approval_id', 'active', 'created_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'until' => 'date',
            'active' => 'boolean',
        ];
    }
}
