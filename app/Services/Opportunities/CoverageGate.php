<?php

namespace App\Services\Opportunities;

use App\Models\Opportunity;

interface CoverageGate
{
    public function proposalAllowed(Opportunity $opportunity): bool;
}
