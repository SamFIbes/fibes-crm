<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Collections\CollectionsDesk;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RequestReactivation implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $customerId, public int $actorId) {}

    public function handle(CollectionsDesk $collections): void
    {
        $actor = User::query()->find($this->actorId);

        if ($actor instanceof User) {
            $collections->reactivateRequest($this->customerId, $actor);
        }
    }
}
