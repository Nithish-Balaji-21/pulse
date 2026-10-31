<?php

use App\Jobs\Middleware\RecordJobRunCompletion;
use App\Jobs\Pulse\ProcessDeviceDataForPithJob;
use App\Models\Pulse\JobRun;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Pulse\Support\TeamFactory;

/**
 * `cron_runs` records that a command dispatched, at whole-command granularity, so it
 * reports success even when every queued job is lost. `pulse_job_runs` records one row
 * per team per job and is closed by the job itself, making an unprocessed team visible.
 */
beforeEach(function () {
    $this->yesterday = Carbon::yesterday()->toDateString();
});

it('opens a ledger entry per team on the scheduled run', function () {
    $teamId = TeamFactory::create('Ledger Factory '.uniqid());
    DB::table('pulse_devices')->insert([
        'team_id' => $teamId,
        'device_id' => 90001,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('pulse_shifts')->insert([
        'team_id' => $teamId,
        'name' => 'Shift 1',
        'is_active' => 1,
        'start_time' => '08:00:00',
        'end_time' => '16:00:00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->artisan('devicedata:pith')->assertSuccessful();

    $entry = JobRun::where('team_id', $teamId)
        ->where('job_name', 'ProcessDeviceDataForPithJob')
        ->where('run_date', $this->yesterday)
        ->first();

    expect($entry)->not->toBeNull()
        ->and($entry->dispatched_at)->not->toBeNull()
        ->and($entry->completed_at)->toBeNull();
});

it('does not open ledger entries for a manual backfill', function () {
    $teamId = TeamFactory::create('Backfill Factory '.uniqid());
    DB::table('pulse_devices')->insert([
        'team_id' => $teamId,
        'device_id' => 90002,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('pulse_shifts')->insert([
        'team_id' => $teamId,
        'name' => 'Shift 1',
        'is_active' => 1,
        'start_time' => '08:00:00',
        'end_time' => '16:00:00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->artisan('devicedata:pith', ['--team_ids' => [$teamId]])->assertSuccessful();

    expect(JobRun::where('team_id', $teamId)->exists())->toBeFalse();
});

it('closes the entry when the job middleware runs after a successful handle', function () {
    $teamId = TeamFactory::create('Completion Factory '.uniqid());

    JobRun::recordDispatch('ProcessDeviceDataForPithJob', $teamId, $this->yesterday);

    $job = new ProcessDeviceDataForPithJob($teamId, 90003, [$this->yesterday]);

    // Stand in for the queue pipeline: the middleware only records once $next() returns,
    // which is what keeps a throwing job from being marked complete.
    (new RecordJobRunCompletion)->handle($job, fn () => null);

    $entry = JobRun::where('team_id', $teamId)->first();

    expect($entry->completed_at)->not->toBeNull();
});

it('leaves the entry open when the job throws', function () {
    $teamId = TeamFactory::create('Failure Factory '.uniqid());

    JobRun::recordDispatch('ProcessDeviceDataForPithJob', $teamId, $this->yesterday);

    $job = new ProcessDeviceDataForPithJob($teamId, 90004, [$this->yesterday]);

    expect(fn () => (new RecordJobRunCompletion)->handle($job, function () {
        throw new RuntimeException('processing blew up');
    }))->toThrow(RuntimeException::class);

    expect(JobRun::where('team_id', $teamId)->first()->completed_at)->toBeNull();
});

it('re-opens a completed entry when the same team and date are dispatched again', function () {
    $teamId = TeamFactory::create('Redispatch Factory '.uniqid());

    JobRun::recordDispatch('ProcessDeviceDataForPithJob', $teamId, $this->yesterday);
    JobRun::recordCompletion('ProcessDeviceDataForPithJob', $teamId, $this->yesterday);
    JobRun::recordDispatch('ProcessDeviceDataForPithJob', $teamId, $this->yesterday);

    expect(JobRun::where('team_id', $teamId)->count())->toBe(1)
        ->and(JobRun::where('team_id', $teamId)->first()->completed_at)->toBeNull();
});

it('reports a dispatched but uncompleted team as unprocessed', function () {
    $teamId = TeamFactory::create('Unprocessed Factory '.uniqid());

    JobRun::recordDispatch('ProcessDeviceDataForPithJob', $teamId, $this->yesterday);

    $incomplete = JobRun::incompleteOn($this->yesterday);

    expect($incomplete->pluck('team_id'))->toContain($teamId);
});

it('does not report a completed team as unprocessed', function () {
    $teamId = TeamFactory::create('Processed Factory '.uniqid());

    JobRun::recordDispatch('ProcessDeviceDataForPithJob', $teamId, $this->yesterday);
    JobRun::recordCompletion('ProcessDeviceDataForPithJob', $teamId, $this->yesterday);

    expect(JobRun::incompleteOn($this->yesterday)->pluck('team_id'))->not->toContain($teamId);
});

it('keeps each submodule job distinct for the same team and date', function () {
    $teamId = TeamFactory::create('Submodule Factory '.uniqid());

    JobRun::recordDispatch('ProcessDeviceDataForModBalesJob', $teamId, $this->yesterday);
    JobRun::recordDispatch('ProcessDeviceDataForModWaterJob', $teamId, $this->yesterday);
    JobRun::recordCompletion('ProcessDeviceDataForModBalesJob', $teamId, $this->yesterday);

    // devicedata:others dispatches up to three jobs per team; completing one must not
    // mask the others, which a command-name-keyed ledger would have done.
    expect(JobRun::incompleteOn($this->yesterday)->pluck('job_name'))
        ->toContain('ProcessDeviceDataForModWaterJob')
        ->not->toContain('ProcessDeviceDataForModBalesJob');
});
