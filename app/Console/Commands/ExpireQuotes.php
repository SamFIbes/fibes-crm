<?php

namespace App\Console\Commands;

use App\Services\Quotes\QuoteDesk;
use Illuminate\Console\Command;

class ExpireQuotes extends Command
{
    protected $signature = 'quotes:expire';

    protected $description = 'Mark sent quotes past their valid date as expired';

    public function handle(QuoteDesk $quotes): int
    {
        $count = $quotes->expireDue();
        $this->info($count.' quotes expired.');

        return self::SUCCESS;
    }
}
