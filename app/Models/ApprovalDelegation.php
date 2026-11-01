<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApprovalDelegation extends Model
{
    protected $fillable = ['user_id', 'delegate_id', 'starts_on', 'ends_on'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }
}
