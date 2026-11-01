<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('tickets:sla')->everyMinute();
Schedule::command('tasks:remind')->everyMinute();
Schedule::command('tasks:generate')->hourly();
Schedule::command('leads:sla')->everyMinute();
Schedule::command('approvals:sla')->everyMinute();
Schedule::command('quotes:expire')->daily();
Schedule::command('billing:run')->dailyAt('06:00');
Schedule::command('collections:run')->dailyAt('06:30');
Schedule::command('retention:run')->dailyAt('07:00');
Schedule::command('reports:digest')->hourly();
Schedule::command('automations:run')->hourly();
Schedule::command('backups:run')->dailyAt('02:00');
Schedule::command('backups:restore-test')->monthlyOn(1, '04:00');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
