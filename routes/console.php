<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('bookings:generate-recurring')->monthly();
Schedule::command('bookings:send-reminders')->dailyAt('08:00');
Schedule::command('members:send-payment-reminders')->dailyAt('09:00');
Schedule::command('members:send-renewal-reminders')->dailyAt('10:00');
Schedule::command('members:deactivate-expired')->dailyAt('02:00');
Schedule::command('events:send-reminders')->hourly();
Schedule::command('surveys:process')->everyFifteenMinutes();
Schedule::command('mailings:process')->everyFiveMinutes();
Schedule::command('mail:import-imap')->everyFiveMinutes()->withoutOverlapping()->runInBackground();
Schedule::command('org-roles:sync-permissions')->dailyAt('03:00');
Schedule::command('workspace:sync-delegates')->dailyAt('03:30');
Schedule::command('workspace:purge-pending-deletions')->dailyAt('03:15');
Schedule::command('workspace:sync-drives')->dailyAt('03:45');
Schedule::command('accounting:fetch-inbox')->everyFifteenMinutes();
Schedule::command('signing:sync')->everyFifteenMinutes(); // DocSign — reserv om callbacks uteblir
Schedule::command('signing:prune-external')->monthlyOn(1, '05:00'); // avstängd tills DOCSIGN_EXTERNAL_RETENTION_YEARS sätts
Schedule::command('accounting:create-fiscal-year')->yearlyOn(11, 1, '06:00'); // 1 november varje år
Schedule::command('db:backup')->dailyAt('02:00');
Schedule::command('zettle:sync')->dailyAt('04:00');
Schedule::command('sumup:sync')->dailyAt('04:30');
Schedule::command('discord:sync-roles')->dailyAt('04:15');
Schedule::command('discord:booking-summary')->dailyAt('07:00');
Schedule::command('discord:booking-overview')->dailyAt('00:05');