<?php

use App\Jobs\Middleware\RecordJobRunCompletion;
use App\Jobs\Pulse\ProcessDeviceDataForModWaterJob;
use App\Models\Pulse\JobRun;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Pulse\Support\TeamFactory;

/**
 * The water job discovers the shift dates its raw readings belong to, which need not be
 * the single date it was dispatched for. That discovery must not disturb the ledger key:
 * `system:check_errors` reports a team as unprocessed purely from an entry left open,
 * so a job that finished its work and closed nothing pages a superadmin for nothing.
 */
beforeEach(function () {
    $this->yesterday = Carbon::yesterday()->toDateString();
    $this->deviceId = 90501;
});

/**
 * Run the job the way the queue does — middleware wrapped around a real handle().
 */
function runWaterJobThroughLedger(int $teamId, int $deviceId, array $dates): void
{
    $job = new ProcessDeviceDataForModWaterJob($teamId, $deviceId, $dates);

    (new RecordJobRunCompletion)->handle($job, fn ($job) => $job->handle());
}

function insertWaterReading(int $deviceId, string $meterDatetime, string $createdAt, int $counter): void
{
    DB::table('pulse_device_data_water_meters')->insert([
        'device_id' => $deviceId,
        'field_id' => config('app.water_consumption.total'),
        'meter_date' => Carbon::parse($meterDatetime)->toDateString(),
        'meter_datetime' => $meterDatetime,
        'litres_counter' => $counter,
        'is_duplicate' => 0,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
}

it('closes the ledger entry when the device reported nothing that day', function () {
    $teamId = TeamFactory::create('Silent Water Factory '.uniqid());

    JobRun::recordDispatch('ProcessDeviceDataForModWaterJob', $teamId, $this->yesterday);

    // No readings at all: discovery returns no dates, but the job still ran to completion.
    runWaterJobThroughLedger($teamId, $this->deviceId, [$this->yesterday]);

    expect(JobRun::where('team_id', $teamId)->first()->completed_at)->not->toBeNull()
        ->and(JobRun::incompleteOn($this->yesterday)->pluck('team_id'))->not->toContain($teamId);
});

it('closes the dispatched entry when readings span more than one meter date', function () {
    $teamId = TeamFactory::create('Straddling Water Factory '.uniqid());

    JobRun::recordDispatch('ProcessDeviceDataForModWaterJob', $teamId, $this->yesterday);

    // A reading that arrives just after midnight still carries the previous meter date,
    // so a day's uploads routinely discover two dates rather than the one dispatched.
    $dayBefore = Carbon::parse($this->yesterday)->subDay()->toDateString();
    insertWaterReading($this->deviceId, $dayBefore.' 23:58:00', $this->yesterday.' 00:01:00', 1000);
    insertWaterReading($this->deviceId, $this->yesterday.' 09:00:00', $this->yesterday.' 09:00:05', 1500);

    runWaterJobThroughLedger($teamId, $this->deviceId, [$this->yesterday]);

    expect(JobRun::where('team_id', $teamId)->first()->completed_at)->not->toBeNull();
});

it('closes the dispatched entry when the only discovered date is an earlier one', function () {
    $teamId = TeamFactory::create('Backdated Water Factory '.uniqid());

    JobRun::recordDispatch('ProcessDeviceDataForModWaterJob', $teamId, $this->yesterday);

    // Everything uploaded yesterday belongs to the day before: the closure must not be
    // redirected onto that older run_date, leaving yesterday's entry open.
    $dayBefore = Carbon::parse($this->yesterday)->subDay()->toDateString();
    insertWaterReading($this->deviceId, $dayBefore.' 22:00:00', $this->yesterday.' 00:05:00', 2000);

    runWaterJobThroughLedger($teamId, $this->deviceId, [$this->yesterday]);

    expect(JobRun::where('team_id', $teamId)->first()->completed_at)->not->toBeNull();
});

it('leaves a multi-date backfill unledgered', function () {
    $teamId = TeamFactory::create('Backfill Water Factory '.uniqid());

    $dayBefore = Carbon::parse($this->yesterday)->subDay()->toDateString();

    runWaterJobThroughLedger($teamId, $this->deviceId, [$dayBefore, $this->yesterday]);

    expect(JobRun::where('team_id', $teamId)->exists())->toBeFalse();
});

it('writes summary rows only for real meter fields, not the config thresholds', function () {
    $teamId = TeamFactory::create('Field Id Water Factory '.uniqid());

    insertWaterReading($this->deviceId, $this->yesterday.' 06:00:00', $this->yesterday.' 06:00:02', 500);
    insertWaterReading($this->deviceId, $this->yesterday.' 18:00:00', $this->yesterday.' 18:00:02', 900);

    runWaterJobThroughLedger($teamId, $this->deviceId, [$this->yesterday]);

    $fieldIds = DB::table('pulse_summary_water_meter')
        ->where('team_id', $teamId)->pluck('field_id')->all();

    // 100000 and 6000 are the daily/hourly limits sharing the config group, not fields.
    expect($fieldIds)->toEqualCanonicalizing(['d27', 'd16'])
        ->and($fieldIds)->not->toContain('100000')
        ->and($fieldIds)->not->toContain('6000');
});

it('warns distinctly when no readings remain, without failing the run', function () {
    $teamId = TeamFactory::create('Silent Warning Factory '.uniqid());

    JobRun::recordDispatch('ProcessDeviceDataForModWaterJob', $teamId, $this->yesterday);

    Log::spy();

    runWaterJobThroughLedger($teamId, $this->deviceId, [$this->yesterday]);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message) => str_contains($message, 'WATER METER NO DATA')
            && str_contains($message, (string) $teamId)
            && str_contains($message, $this->yesterday))
        ->once();

    // A silent meter is a device fault, so nothing here may look like a processing
    // failure: no error line, and the ledger entry closes so system:check_errors
    // leaves it out of the missed-run email.
    Log::shouldNotHaveReceived('error');

    expect(JobRun::where('team_id', $teamId)->first()->completed_at)->not->toBeNull();
});

it('keeps a silent meter out of the missed-run report', function () {
    $teamId = TeamFactory::create('Silent Report Factory '.uniqid());

    JobRun::recordDispatch('ProcessDeviceDataForModWaterJob', $teamId, $this->yesterday);
    runWaterJobThroughLedger($teamId, $this->deviceId, [$this->yesterday]);

    Log::spy();

    $this->artisan('system:check_errors')->assertSuccessful();

    Log::shouldNotHaveReceived('error', [
        Mockery::on(fn ($message) => is_string($message)
            && str_contains($message, 'TEAM NOT PROCESSED')
            && str_contains($message, (string) $teamId)),
    ]);
});
