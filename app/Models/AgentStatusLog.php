<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentStatusLog extends Model
{
    protected $table = 'agent_status_log';

    protected $fillable = ['user_id', 'status', 'started_at', 'ended_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }
}
