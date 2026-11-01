<?php

namespace App\Policies;

use App\Models\Lead;
use App\Models\User;
use App\Services\Leads\LeadDesk;
use App\Support\Access\AccessGate;

class LeadPolicy
{
    public function __construct(
        private AccessGate $access,
        private LeadDesk $leads,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allows($user, 'leads.view');
    }

    public function view(User $user, Lead $lead): bool
    {
        return $this->leads->visible($user)->whereKey($lead->id)->exists();
    }

    public function create(User $user): bool
    {
        return $this->access->allows($user, 'leads.create');
    }

    public function update(User $user, Lead $lead): bool
    {
        if (! $this->view($user, $lead) || ! $this->access->allows($user, 'leads.edit')) {
            return false;
        }

        if ($this->access->scopeFor($user, 'leads.edit') === 'own') {
            return (int) $lead->owner_id === $user->id;
        }

        return true;
    }

    public function claim(User $user, Lead $lead): bool
    {
        return $this->access->allows($user, 'leads.claim');
    }

    public function assign(User $user, Lead $lead): bool
    {
        return $this->view($user, $lead) && $this->access->allows($user, 'leads.assign');
    }
}
