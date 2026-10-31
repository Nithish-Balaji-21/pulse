<?php

use App\Console\Commands\CheckSystemErrorsCron;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schedule;
use Laravel\Nightwatch\Console\Sample;

$env = config('app.env');

// Daily processing. The slot lives in config because system:check_errors below derives
// its own earliest useful run time from it, and is read through the command so a config
// key that cannot be resolved degrades to the shipped default instead of throwing here.
// This file loads on every console boot, so an exception takes the whole scheduler with it.
$deviceCronAt = CheckSystemErrorsCron::deviceCronAt();

foreach ([
    'devicedata:pith',
    'devicedata:fibre',
    'devicedata:growbags',
    'devicedata:others',
] as $command) {
    Schedule::command($command)->dailyAt($deviceCronAt);
}

Schedule::command('husks:daily-stock')->dailyAt('00:15');

// Monitoring & maintenance
Schedule::command('queue-monitor:prune')->dailyAt('03:30')->withoutOverlapping();
// The external service, rather than Nightwatch, alerts when this ping stops.
// Do not consume a Nightwatch event every minute for the heartbeat itself.
Schedule::command('monitor:scheduler-heartbeat')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping()
    ->tap(Sample::never());

// This verifies that delayed per-factory jobs actually finish after their shift starts.
// The command deduplicates identical email alerts, so frequent checks do not spam operators.
//
// It only starts once the nightly commands have had their grace period to dispatch.
// Polling from 00:00 meant the first run of every day judged commands that were not
// scheduled until 00:10 and emailed the superadmins that all four had been missed.
// The command enforces the same cut-off from the same config; this filter just saves
// booting it for the handful of slots before then.
$errorCheckFrom = Carbon::createFromTimeString($deviceCronAt)
    ->addMinutes(CheckSystemErrorsCron::deviceCronStartGraceMins())
    ->format('H:i');

Schedule::command('system:check_errors')
    ->everyFiveMinutes()
    ->between($errorCheckFrom, '23:59')
    ->onOneServer()
    ->withoutOverlapping();
Schedule::command('data:cleanup')->weeklyOn(0, '00:00');

// Weather
Schedule::command('weather:populate_hourly')->cron('30 6-18 * * *');
Schedule::command('weather:populate_daily')->cron('0 6,12,18 * * *');

// Inventory
Schedule::command('pith:inventory-snapshot')->dailyAt('23:59');

// Production only
if ($env === 'production') {

    // Production summaries
    Schedule::command('production:send-summary --type=weekly')->weeklyOn(1, '09:30');
    Schedule::command('production:send-summary --type=monthly')->monthlyOn(1, '10:00');
    Schedule::command('production:send-summary --type=yearly')->cron('0 3 2 1 *');

    // License management
    Schedule::command('teams:deactivate-expired')->dailyAt('02:00');
    Schedule::command('alerts:license-expiry')->dailyAt('09:00');

    // Monitoring
    Schedule::command('alerts:device-failure')->everyFiveMinutes();

    foreach ([
        'pulse:monitor-machine-states',
        'pulse:monitor-water-tanks',
    ] as $command) {
        Schedule::command($command)->everyFiveMinutes()->withoutOverlapping();
    }

    Schedule::command('horizon:snapshot')->everyFiveMinutes();
}

// Demo only
if ($env === 'demo') {
    Schedule::command('demo:generate-data')->hourly();
    Schedule::command('demo:simulate-actuals', ['--date' => Carbon::yesterday()->toDateString()])->dailyAt('09:35');
}
