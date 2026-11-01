<?php

namespace App\Console\Commands;

use App\Mail\ReportMail;
use App\Models\ReportDigest;
use App\Models\ReportSchedule;
use App\Models\User;
use App\Services\Reports\ReportDesk;
use App\Services\Settings\CommercialSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendReportDigests extends Command
{
    protected $signature = 'reports:digest';

    protected $description = 'Send scheduled reports and the supervisor digest when the clock matches Settings.';

    public function handle(CommercialSettings $commercial, ReportDesk $reports): int
    {
        $hour = (int) $commercial->get('digest_hour');

        if (now()->hour !== $hour) {
            return self::SUCCESS;
        }

        $today = now()->toDateString();
        $this->schedules($reports, $today, $hour);
        $this->digest($commercial, $reports, 'daily', $today);

        if (now()->dayOfWeekIso === (int) $commercial->get('digest_weekday')) {
            $this->digest($commercial, $reports, 'weekly', $today);
        }

        return self::SUCCESS;
    }

    private function schedules(ReportDesk $reports, string $today, int $hour): void
    {
        ReportSchedule::query()->where('hour', $hour)->orderBy('id')->each(function (ReportSchedule $schedule) use ($reports, $today): void {
            if ($schedule->last_sent_on?->toDateString() === $today) {
                return;
            }

            if ($schedule->cadence === 'weekly' && now()->dayOfWeekIso !== (int) ($schedule->weekday ?: 1)) {
                return;
            }

            $user = User::query()->find($schedule->user_id);

            if (! $user instanceof User || ! $reports->allows($user, (string) $schedule->report_key)) {
                return;
            }

            $from = $reports->defaultFrom();
            $to = $reports->defaultTo();
            $value = $reports->value((string) $schedule->report_key, $user, $from, $to);
            Mail::to($user->email)->send(new ReportMail((string) $schedule->report_key, $value, url('/reports/'.$schedule->report_key)));
            $schedule->last_sent_on = $today;
            $schedule->save();
        });
    }

    private function digest(CommercialSettings $commercial, ReportDesk $reports, string $kind, string $today): void
    {
        if (ReportDigest::query()->where('kind', $kind)->whereDate('sent_on', $today)->exists()) {
            return;
        }

        $roles = array_filter(array_map('trim', explode(',', (string) $commercial->get('digest_roles'))));
        $from = $reports->defaultFrom();
        $to = $reports->defaultTo();

        User::query()->whereIn('role', $roles)->whereNotNull('email')->orderBy('id')->each(function (User $user) use ($reports, $from, $to, $kind): void {
            $lines = [];

            foreach (array_slice($reports->allowedKeys($user), 0, 4) as $key) {
                $lines[] = $key.' '.$reports->value($key, $user, $from, $to);
            }

            Mail::to($user->email)->send(new ReportMail($kind === 'weekly' ? 'Weekly digest' : 'Daily digest', implode(', ', $lines), url('/dashboard')));
        });

        ReportDigest::query()->create(['kind' => $kind, 'sent_on' => $today]);
    }
}
