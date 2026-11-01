<?php

namespace App\Policies;

use App\Models\Opportunity;
use App\Models\User;
use App\Services\Opportunities\OpportunityDesk;
use App\Support\Access\AccessGate;

class OpportunityPolicy
{
    public function __construct(
        private AccessGate $access,
        private OpportunityDesk $deals,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allows($user, 'opportunities.view')
            || $this->access->allows($user, 'opportunities.view_won');
    }

    public function view(User $user, Opportunity $opportunity): bool
    {
        return $this->deals->visible($user)->whereKey($opportunity->id)->exists();
    }

    public function update(User $user, Opportunity $opportunity): bool
    {
        if (! $this->view($user, $opportunity) || ! $this->access->allows($user, 'opportunities.edit')) {
            return false;
        }

        if ($this->access->scopeFor($user, 'opportunities.edit') === 'own') {
            return (int) $opportunity->owner_id === $user->id;
        }

        return true;
    }
}
