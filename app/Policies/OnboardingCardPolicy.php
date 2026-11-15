<?php

namespace App\Policies;

use App\Models\OnboardingCard;
use App\Models\User;
use App\Services\Onboarding\OnboardingDesk;

class OnboardingCardPolicy
{
    public function __construct(private OnboardingDesk $desk) {}

    public function viewAny(User $user): bool
    {
        return $this->desk->canView($user);
    }

    public function view(User $user, OnboardingCard $card): bool
    {
        return $this->desk->canView($user)
            && $this->desk->visible($user)->whereKey($card->id)->exists();
    }

    public function create(User $user): bool
    {
        return $this->desk->canEdit($user);
    }

    public function update(User $user, OnboardingCard $card): bool
    {
        return $this->desk->canEdit($user) && $this->view($user, $card);
    }

    public function manageSettings(User $user): bool
    {
        return $this->desk->canManage($user);
    }
}
