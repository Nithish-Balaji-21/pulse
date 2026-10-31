<?php

use App\Support\Pulse\CounterSeed;
use Illuminate\Support\Facades\DB;

/**
 * The demo generator writes nothing on a factory's off day, which used to leave its device
 * looking dead for a day or more — and {@see CounterSeed} then declines to
 * carry the counter into the day production resumes, because it cannot tell a closed factory
 * from a device that died while the machine kept running.
 *
 * A powered but idle device does report. These cover the off-day branches that must now leave
 * one flat-counter reading behind, one per module that has them.
 */
function demoDevices(): array
{
    $devices = [];

    foreach (['fibre' => 91001, 'pith' => 91002, 'growbags' => 91003] as $module => $deviceId) {
        $teamId = (int) DB::table('teams')->insertGetId([
            'name' => 'Demo '.$module.' '.uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('pulse_devices')->insert([
            'team_id' => $teamId,
            'device_id' => $deviceId,
            'module' => $module,
            'status' => 'active',
        ]);

        $devices[$module] = $deviceId;
    }

    return $devices;
}

it('leaves a flat reading behind on a growbag off day', function () {
    $this->travelTo('2026-09-02 09:00:00');
    $devices = demoDevices();

    // Friday, the last day the machines ran. One counter per machine, kept far apart.
    foreach (['D31' => 1000500, 'D32' => 2000500, 'D34' => 3000500] as $field => $counter) {
        DB::table('pulse_device_data_growbags')->insert([
            'device_id' => $devices['growbags'],
            'field_id' => $field,
            'growbag_date' => '2026-08-28',
            'growbag_datetime' => '2026-08-28 10:00:00',
            'growbags_counter' => $counter,
            'eb_units_counter' => 4000.0,
            'is_duplicate' => 0,
        ]);
    }

    // 2026-08-30 is a Sunday, which the growbag factory takes off.
    $this->artisan('demo:generate-data', ['--date' => '2026-08-30', '--factory' => 'growbags'])
        ->assertSuccessful();

    $heartbeats = DB::table('pulse_device_data_growbags')
        ->where('device_id', $devices['growbags'])
        ->where('growbag_date', '2026-08-30')
        ->orderBy('field_id')
        ->get();

    expect($heartbeats)->toHaveCount(3)
        ->and($heartbeats->pluck('growbags_counter')->all())->toBe([1000500, 2000500, 3000500])
        ->and($heartbeats->pluck('eb_units_counter')->unique()->all())->toBe([4000.0])
        ->and($heartbeats->first()->growbag_datetime)->toBe('2026-08-30 12:00:00');
});

it('leaves a flat reading behind on a pith bale off day', function () {
    $this->travelTo('2026-09-02 09:00:00');
    $devices = demoDevices();

    DB::table('pulse_device_data_fibre_bales')->insert([
        'device_id' => $devices['pith'],
        'bale_date' => '2026-08-28',
        'bale_datetime' => '2026-08-28 10:00:00',
        'bales_counter' => 7700,
        'eb_units_counter' => 8800,
        'is_duplicate' => 0,
    ]);

    $this->artisan('demo:generate-data', ['--date' => '2026-08-30', '--factory' => 'pith'])
        ->assertSuccessful();

    $heartbeat = DB::table('pulse_device_data_fibre_bales')
        ->where('device_id', $devices['pith'])
        ->where('bale_date', '2026-08-30')
        ->first();

    expect($heartbeat)->not->toBeNull()
        ->and((int) $heartbeat->bales_counter)->toBe(7700)
        ->and($heartbeat->bale_datetime)->toBe('2026-08-30 12:00:00');
});

it('leaves a flat reading behind on a fibre off day', function () {
    $this->travelTo('2026-09-02 09:00:00');
    $devices = demoDevices();

    DB::table('pulse_device_data_fibre_bales')->insert([
        'device_id' => $devices['fibre'],
        'bale_date' => '2026-08-31',
        'bale_datetime' => '2026-08-31 10:00:00',
        'bales_counter' => 5500,
        'eb_units_counter' => 6600,
        'is_duplicate' => 0,
    ]);

    // 2026-09-01 is a Tuesday, which the fibre factory takes off.
    $this->artisan('demo:generate-data', ['--date' => '2026-09-01', '--factory' => 'fibre'])
        ->assertSuccessful();

    $heartbeat = DB::table('pulse_device_data_fibre_bales')
        ->where('device_id', $devices['fibre'])
        ->where('bale_date', '2026-09-01')
        ->first();

    expect($heartbeat)->not->toBeNull()
        ->and((int) $heartbeat->bales_counter)->toBe(5500)
        ->and($heartbeat->bale_datetime)->toBe('2026-09-01 12:00:00');
});

it('writes no heartbeat for a device that has never reported', function () {
    $this->travelTo('2026-09-02 09:00:00');
    $devices = demoDevices();

    $this->artisan('demo:generate-data', ['--date' => '2026-08-30', '--factory' => 'growbags'])
        ->assertSuccessful();

    // Nothing has ever been read from it, so there is no counter to hold and no chain to keep.
    expect(DB::table('pulse_device_data_growbags')->where('device_id', $devices['growbags'])->count())->toBe(0);
});
