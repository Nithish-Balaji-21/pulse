<?php

use App\Console\Commands\CheckSystemErrorsCron;
use App\Models\Pulse\JobRun;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Tests\Pulse\Support\TeamFactory;

/**
 * The nightly commands are scheduled for `app.daily_device_cron_at`, but this checker
 * polls every five minutes from the top of the day. Its first run therefore landed
 * before the commands had been fired at all and reported every one of them missed,
 * emailing the superadmins a four-command outage every single midnight.
 *
 * Nothing it looks at can conclude anything that early, so the guard holds the whole
 * run — the ledger scan included — until the commands have had their grace period.
 */
beforeEach(function () {
    Notification::fake();

    $this->today = Carbon::today()->toDateString();
    $this->yesterday = Carbon::yesterday()->toDateString();

    $this->dueAt = Carbon::today()
        ->setTimeFromTimeString(config('app.daily_device_cron_at'))
        ->addMinutes((int) config('app.daily_device_cron_start_grace_mins'));

    // The commands under test insert these at the end of their run; absent rows are
    // what the missed-cron check keys on.
    DB::table('cron_runs')->where('run_date', $this->today)->delete();
});

afterEach(function () {
    Carbon::setTestNow();
});

function loggedCronMissed(): Closure
{
    return fn ($message) => is_string($message) && str_contains($message, 'CRON MISSED');
}

it('does not report a missed cron before the nightly commands are due', function () {
    Carbon::setTestNow($this->dueAt->copy()->subMinute());
    Log::spy();

    $this->artisan('system:check_errors')->assertSuccessful();

    Log::shouldNotHaveReceived('error', [Mockery::on(loggedCronMissed())]);
    Notification::assertNothingSent();
});

it('reports every missed cron once the grace period has elapsed', function () {
    Carbon::setTestNow($this->dueAt->copy());
    Log::spy();

    $this->artisan('system:check_errors')->assertSuccessful();

    foreach (['devicedata:pith', 'devicedata:fibre', 'devicedata:growbags', 'devicedata:others'] as $jobName) {
        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message) => str_contains($message, 'CRON MISSED')
                && str_contains($message, $jobName))
            ->atLeast()->once();
    }
});

it('stays quiet at the due time when every command recorded its run', function () {
    foreach (['devicedata:pith', 'devicedata:fibre', 'devicedata:growbags', 'devicedata:others'] as $jobName) {
        DB::table('cron_runs')->insert([
            'job_name' => $jobName,
            'run_date' => $this->today,
            'ran_at' => now(),
        ]);
    }

    Carbon::setTestNow($this->dueAt->copy());
    Log::spy();

    $this->artisan('system:check_errors')->assertSuccessful();

    Log::shouldNotHaveReceived('error', [Mockery::on(loggedCronMissed())]);
});

it('holds the ledger scan too, so a stale entry cannot alert at midnight', function () {
    // An entry that is genuinely past due: dispatched yesterday, never completed. Before
    // the guard covered the whole command this still produced a midnight email, because
    // only the missed-cron block was gated.
    $teamId = TeamFactory::create('Midnight Factory '.uniqid());

    JobRun::create([
        'job_name' => 'ProcessDeviceDataForPithJob',
        'team_id' => $teamId,
        'run_date' => $this->yesterday,
        'dispatched_at' => Carbon::yesterday()->setTime(0, 10),
        'completed_at' => null,
    ]);

    Carbon::setTestNow($this->dueAt->copy()->subMinute());
    Log::spy();

    $this->artisan('system:check_errors')->assertSuccessful();

    Log::shouldNotHaveReceived('error', [
        Mockery::on(fn ($message) => is_string($message) && str_contains($message, 'TEAM NOT PROCESSED')),
    ]);
    Notification::assertNothingSent();
});

it('derives its cut-off from the configured slot rather than a hardcoded time', function () {
    // Moving the nightly slot must move the checker with it. A constant copy of '00:10'
    // in the command is what let the two drift apart in the first place.
    config(['app.daily_device_cron_at' => '04:00', 'app.daily_device_cron_start_grace_mins' => 20]);

    Carbon::setTestNow(Carbon::today()->setTime(4, 19));
    Log::spy();

    $this->artisan('system:check_errors')->assertSuccessful();

    Log::shouldNotHaveReceived('error', [Mockery::on(loggedCronMissed())]);

    Carbon::setTestNow(Carbon::today()->setTime(4, 20));
    Log::spy();

    $this->artisan('system:check_errors')->assertSuccessful();

    Log::shouldHaveReceived('error')
        ->withArgs(loggedCronMissed())
        ->atLeast()->once();
});

it('falls back to the shipped defaults when the config keys are unreadable', function () {
    // A deploy that pulls new config keys against a stale config cache resolves them as
    // null. routes/console.php reads them while the console kernel boots, so throwing
    // there took down every scheduled command — including the config:clear that fixes it.
    config(['app.daily_device_cron_at' => null, 'app.daily_device_cron_start_grace_mins' => null]);

    expect(CheckSystemErrorsCron::deviceCronAt())->toBe(CheckSystemErrorsCron::FALLBACK_DEVICE_CRON_AT)
        ->and(CheckSystemErrorsCron::deviceCronStartGraceMins())->toBe(CheckSystemErrorsCron::FALLBACK_START_GRACE_MINS);

    // The schedule derives its window from these, so the value must stay a usable H:i.
    expect(fn () => Carbon::createFromTimeString(CheckSystemErrorsCron::deviceCronAt()))
        ->not->toThrow(TypeError::class);

    Carbon::setTestNow(Carbon::today()->setTime(0, 24));
    Log::spy();

    $this->artisan('system:check_errors')->assertSuccessful();

    Log::shouldNotHaveReceived('error', [Mockery::on(loggedCronMissed())]);
});

it('still prefers a configured slot over the fallback', function () {
    config(['app.daily_device_cron_at' => '03:30', 'app.daily_device_cron_start_grace_mins' => 5]);

    expect(CheckSystemErrorsCron::deviceCronAt())->toBe('03:30')
        ->and(CheckSystemErrorsCron::deviceCronStartGraceMins())->toBe(5);
});
