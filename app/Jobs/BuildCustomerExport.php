<?php

namespace App\Jobs;

use App\Services\Imports\TableExport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class BuildCustomerExport implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $exportId) {}

    public function handle(TableExport $exports): void
    {
        $exports->write($this->exportId);
    }
}
