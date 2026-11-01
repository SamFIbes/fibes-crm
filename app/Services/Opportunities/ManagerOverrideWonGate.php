<?php

namespace App\Services\Opportunities;

use App\Models\User;

class ManagerOverrideWonGate implements WonGate
{
    public function allowed(User $actor, ?string $reason): bool
    {
        $manager = in_array($actor->role, ['super_admin', 'sales_manager'], true)
            || $actor->hasRole('super_admin')
            || $actor->hasRole('sales_manager');

        return $manager && is_string($reason) && trim($reason) !== '';
    }
}
