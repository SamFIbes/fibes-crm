<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Billing\BillingDesk;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

class BillingRun extends Command
{
    protected $signature = 'billing:run';

    protected $description = 'Issue invoices that are due and move expired subscriptions into grace';

    public function handle(BillingDesk $billing): int
    {
        $actor = User::query()->where('role', 'super_admin')->where('is_active', true)->first();
        $count = $billing->run($actor, Request::create('/'));
        $this->info($count.' invoices issued.');

        return self::SUCCESS;
    }
}
