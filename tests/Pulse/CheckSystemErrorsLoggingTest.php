<?php

use App\Models\Pulse\JobRun;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Tests\Pulse\Support\TeamFactory;

/**
 * Both failure checks must reach laravel.log, not only the alert email. The email is
 * suppressed in local environments, so the log is the only signal there — and the two
 * checks cover different failures: `cron_runs` catches the command never running,
 * the ledger catches jobs that were dispatched but never completed.
 */
beforeEach(function () {
    // Judge dispatched work after the shift anchor, buffer and execution grace.
    $this->travelTo(Carbon::parse('2026-09-26 13:00:00'));
    Notification::fake();
    $this->yesterday = Carbon::yesterday()->toDateString();
});

afterEach(function () {
    $this->travelBack();
});

it('logs an error for a team dispatched but never completed', function () {
    $teamId = TeamFactory::create('Unlogged Factory '.uniqid());

    JobRun::recordDispatch('ProcessDeviceDataForPithJob', $teamId, $this->yesterday);

    Log::spy();

    $this->artisan('system:check_errors')->assertSuccessful();

    Log::shouldHaveReceived('error')
        ->withArgs(fn ($message) => str_contains($message, 'TEAM NOT PROCESSED')
            && str_contains($message, 'ProcessDeviceDataForPithJob')
            && str_contains($message, $this->yesterday))
        ->atLeast()->once();
});

it('does not log an unprocessed error once the team completes', function () {
    $teamId = TeamFactory::create('Completed Factory '.uniqid());

    JobRun::recordDispatch('ProcessDeviceDataForPithJob', $teamId, $this->yesterday);
    JobRun::recordCompletion('ProcessDeviceDataForPithJob', $teamId, $this->yesterday);

    Log::spy();

    $this->artisan('system:check_errors')->assertSuccessful();

    Log::shouldNotHaveReceived('error', [
        Mockery::on(fn ($message) => is_string($message)
            && str_contains($message, 'TEAM NOT PROCESSED')
            && str_contains($message, (string) $teamId)),
    ]);
});

it('logs an error when the command itself never ran', function () {
    // No cron_runs row for today means the scheduler never fired the command — a
    // failure the ledger cannot see, because no rows were ever opened.
    DB::table('cron_runs')->where('run_date', Carbon::today()->toDateString())->delete();

    Log::spy();

    $this->artisan('system:check_errors')->assertSuccessful();

    Log::shouldHaveReceived('error')
        ->withArgs(fn ($message) => str_contains($message, 'CRON MISSED')
            && str_contains($message, 'devicedata:pith'))
        ->atLeast()->once();
});

it('still logs when the alert email is suppressed in local', function () {
    $teamId = TeamFactory::create('Local Factory '.uniqid());

    JobRun::recordDispatch('ProcessDeviceDataForPithJob', $teamId, $this->yesterday);

    app()['env'] = 'local';

    Log::spy();

    $this->artisan('system:check_errors')->assertSuccessful();

    // The early return that skips the email sits after the logging, so the log survives.
    Log::shouldHaveReceived('error')
        ->withArgs(fn ($message) => str_contains($message, 'TEAM NOT PROCESSED'))
        ->atLeast()->once();
});
