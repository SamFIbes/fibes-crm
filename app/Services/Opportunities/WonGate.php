<?php

namespace App\Services\Opportunities;

use App\Models\User;

interface WonGate
{
    public function allowed(User $actor, ?string $reason): bool;
}
