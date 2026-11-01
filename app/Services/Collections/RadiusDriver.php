<?php

namespace App\Services\Collections;

interface RadiusDriver
{
    public function suspend(string $username): void;

    public function reactivate(string $username): void;
}
