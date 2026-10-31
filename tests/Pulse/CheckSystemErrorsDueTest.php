<?php

use App\Console\Commands\CheckSystemErrorsCron;
use App\Models\Pulse\JobRun;
use App\Models\Pulse\Shift;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Pulse\Support\TeamFactory;

/**
 * Jobs are queued at 00:10 but delayed to each team's shift anchor, so an open ledger
 * entry proves nothing until that delay has elapsed. Before this, a team anchored later
 * than the checker's own slot was reported as unprocessed every single night for jobs
 * that had not yet had a chance to run.
 */
beforeEach(function () {
    $this->shiftDay = Carbon::yesterday()->toDateString();
    $this->dispatchedAt = Carbon::today()->setTime(0, 10)->toDateTimeString();
    Shift::forgetAnchorCache();
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * @return array{0: int, 1: string} team id and the name the log line will carry
 */
function anchorTeam(string $label, string $anchor): array
{
    $name = $label.' '.uniqid();
    $teamId = TeamFactory::create($name);

    DB::table('pulse_settings')->insert([
        'team_id' => $teamId,
        'module' => 'shifts',
        'key' => Shift::ANCHOR_SETTING_KEY,
        'text' => 'Shift day anchor',
        'value' => $anchor,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Shift::forgetAnchorCache($teamId);

    return [$teamId, $name];
}

function openEntry(int $teamId, string $runDate, string $dispatchedAt): void
{
    JobRun::create([
        'job_name' => 'ProcessDeviceDataForModWaterJob',
        'team_id' => $teamId,
        'run_date' => $runDate,
        'dispatched_at' => $dispatchedAt,
        'completed_at' => null,
    ]);
}

/**
 * Match the TEAM NOT PROCESSED line for one specific team. It carries the team NAME, so
 * matching on the id would never fire and would pass any negative assertion vacuously.
 */
function notProcessedFor(string $teamName): Closure
{
    return fn ($message) => is_string($message)
        && str_contains($message, 'TEAM NOT PROCESSED')
        && str_contains($message, $teamName);
}

it('does not report a team whose anchor falls after the check runs', function () {
    // Anchor 18:00 + 15m buffer + 30m window + 15m grace => not due until 19:00.
    [$teamId, $teamName] = anchorTeam('Evening Factory', '18:00:00');
    openEntry($teamId, $this->shiftDay, $this->dispatchedAt);

    Carbon::setTestNow(Carbon::today()->setTime(13, 0));
    Log::spy();

    $this->artisan('system:check_errors')->assertSuccessful();

    Log::shouldNotHaveReceived('error', [Mockery::on(notProcessedFor($teamName))]);
});

it('reports a late-anchored team on a later run, rather than never', function () {
    [$teamId, $teamName] = anchorTeam('Evening Factory Overdue', '18:00:00');
    openEntry($teamId, $this->shiftDay, $this->dispatchedAt);

    // The blind spot the lookback closes: by the time this entry is past due, its shift
    // day is no longer "yesterday", so a single-day query would never look at it again.
    Carbon::setTestNow(Carbon::today()->addDay()->setTime(13, 0));
    Log::spy();

    $this->artisan('system:check_errors')->assertSuccessful();

    Log::shouldHaveReceived('error')
        ->withArgs(notProcessedFor($teamName))
        ->atLeast()->once();
});

it('still reports an early-anchored team at the usual check time', function () {
    // Anchor 08:00 => due by 09:00, so the 13:00 slot judges it exactly as before.
    [$teamId, $teamName] = anchorTeam('Morning Factory', '08:00:00');
    openEntry($teamId, $this->shiftDay, $this->dispatchedAt);

    Carbon::setTestNow(Carbon::today()->setTime(13, 0));
    Log::spy();

    $this->artisan('system:check_errors')->assertSuccessful();

    Log::shouldHaveReceived('error')
        ->withArgs(notProcessedFor($teamName))
        ->atLeast()->once();
});

it('stops reporting a stuck entry once it ages out of the lookback', function () {
    [$teamId, $teamName] = anchorTeam('Stale Factory', '08:00:00');
    openEntry($teamId, $this->shiftDay, $this->dispatchedAt);

    // Bounded alerting: a never-completed entry nobody backfills must not email the
    // superadmins every day forever.
    Carbon::setTestNow(Carbon::today()->addDays(5)->setTime(13, 0));
    Log::spy();

    $this->artisan('system:check_errors')->assertSuccessful();

    Log::shouldNotHaveReceived('error', [Mockery::on(notProcessedFor($teamName))]);
});

it('uses the module buffer that dispatched the job', function () {
    $method = (new ReflectionClass(CheckSystemErrorsCron::class))
        ->getMethod('bufferMinutesFor');
    $instance = app(CheckSystemErrorsCron::class);

    expect($method->invoke($instance, 'ProcessDeviceDataForPithJob'))
        ->toBe((int) config('app.daily_pith_cron_interval_mins'))
        ->and($method->invoke($instance, 'ProcessDeviceDataForFibreJob'))
        ->toBe((int) config('app.daily_fibre_cron_interval_mins'))
        ->and($method->invoke($instance, 'ProcessDeviceDataForGrowBagsJob'))
        ->toBe((int) config('app.growbags.daily_cron_interval_mins'))
        ->and($method->invoke($instance, 'ProcessDeviceDataForModWaterJob'))
        ->toBe((int) config('app.daily_sub_modules_cron_interval_mins'));
});
