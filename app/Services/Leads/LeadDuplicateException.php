<?php

namespace App\Services\Leads;

use RuntimeException;

class LeadDuplicateException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $matches
     */
    public function __construct(public array $matches)
    {
        parent::__construct('A matching lead or customer already exists.');
    }
}
