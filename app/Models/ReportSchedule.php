<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportSchedule extends Model
{
    protected $fillable = ['user_id', 'report_key', 'cadence', 'hour', 'weekday', 'last_sent_on'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['last_sent_on' => 'date'];
    }
}
