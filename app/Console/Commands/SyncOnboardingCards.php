<?php

namespace App\Console\Commands;

use App\Services\Onboarding\OnboardingDesk;
use Illuminate\Console\Command;

class SyncOnboardingCards extends Command
{
    protected $signature = 'onboarding:sync';

    protected $description = 'Create onboarding cards from new sales leads and customers.';

    public function handle(OnboardingDesk $desk): int
    {
        $count = $desk->syncIntake(null);
        $this->info("Created {$count} onboarding cards.");

        return self::SUCCESS;
    }
}
