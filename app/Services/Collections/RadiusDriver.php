<?php

namespace App\Services\Collections;

interface RadiusDriver
{
    public function suspend(string $username, ?int $customerId = null, ?int $actorId = null): void;

    public function reactivate(string $username, ?int $customerId = null, ?int $actorId = null): void;
}
