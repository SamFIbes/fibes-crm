<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketCheck extends Model
{
    protected $fillable = ['ticket_id', 'label_en', 'label_ar', 'required', 'done', 'sort'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'required' => 'boolean',
            'done' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
}
