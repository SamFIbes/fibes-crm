<?php

namespace App\Events;

use App\Models\Opportunity;
use Illuminate\Foundation\Events\Dispatchable;

class OpportunityWon
{
    use Dispatchable;

    public function __construct(public Opportunity $opportunity) {}
}
