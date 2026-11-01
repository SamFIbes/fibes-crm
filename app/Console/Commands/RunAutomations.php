<?php

namespace App\Console\Commands;

use App\Services\Automations\AutomationDesk;
use App\Services\Automations\WebhookDesk;
use Illuminate\Console\Command;

class RunAutomations extends Command
{
    protected $signature = 'automations:run';

    protected $description = 'Run the scheduled automation rules and retry webhook deliveries.';

    public function handle(AutomationDesk $automations, WebhookDesk $webhooks): int
    {
        $fired = $automations->runDue();
        $webhooks->retryDue();
        $this->info('Checked '.$fired.' records.');

        return self::SUCCESS;
    }
}
