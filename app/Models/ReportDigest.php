<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportDigest extends Model
{
    protected $fillable = ['kind', 'sent_on'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['sent_on' => 'date'];
    }
}
