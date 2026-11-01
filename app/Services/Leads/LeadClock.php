<?php

namespace App\Services\Leads;

use App\Models\Holiday;
use App\Services\Settings\SettingsStore;
use Illuminate\Support\Carbon;

class LeadClock
{
    public function __construct(private SettingsStore $settings) {}

    public function minutes(string $key): int
    {
        $sla = $this->settings->get('sla', config('fibes.sla'));

        return max(1, (int) ($sla[$key] ?? config('fibes.sla.'.$key) ?? 15));
    }

    public function isHoliday(Carbon $moment): bool
    {
        $day = $moment->copy()->timezone((string) config('app.timezone'))->toDateString();

        return Holiday::query()->whereDate('observed_on', $day)->exists();
    }

    /**
     * Support is 24/7. A holiday still does not count, including Islamic dates
     * that are waiting for the official moon-sighting confirmation.
     */
    public function addOpenMinutes(Carbon $start, int $minutes): Carbon
    {
        $cursor = $start->copy()->timezone((string) config('app.timezone'));
        $left = $minutes;
        $guard = 0;

        while ($left > 0 && $guard < 400) {
            $guard++;

            if ($this->isHoliday($cursor)) {
                $cursor = $cursor->copy()->addDay()->startOfDay();

                continue;
            }

            $midnight = $cursor->copy()->startOfDay()->addDay();
            $available = (int) floor(($midnight->getTimestamp() - $cursor->getTimestamp()) / 60);

            if ($available < 1) {
                $cursor = $midnight;

                continue;
            }

            $step = min($left, $available);
            $cursor = $cursor->copy()->addMinutes($step);
            $left -= $step;
        }

        return $cursor;
    }
}
