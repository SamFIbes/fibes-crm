<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Clause extends Model
{
    protected $fillable = ['key', 'locale', 'title', 'body', 'sort', 'is_active'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
