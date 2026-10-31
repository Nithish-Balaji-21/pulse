<?php

use App\Jobs\Pulse\ProcessDeviceDataForModBalesJob;
use App\Models\Pulse\Shift;
use Illuminate\Support\Facades\DB;
use Tests\Pulse\Support\TeamFactory;

/**
 * The mini-bale and pith-block modules measure a day as `last - first + 1` over the readings
 * inside it, so everything the machine made between the previous day's last reading and this
 * day's first counted as the single unit that `+1` stands for. They carry the counter across
 * the boundary now, on the same bound as every other module.
 */
function modBalesTeam(string $anchor = '07:00:00'): array
{
    $teamId = TeamFactory::create('Mod Bales Factory '.uniqid(), false, [
        'modules' => [
            'pith' => ['is_active' => true, 'submodules' => ['blocks' => false, 'bales' => true]],
            'fibre' => ['is_active' => false],
        ],
    ]);

    DB::table('pulse_settings')->insert([
        'team_id' => $teamId, 'module' => 'shifts', 'key' => Shift::ANCHOR_SETTING_KEY,
        'text' => 'Shift day anchor', 'value' => $anchor,
    ]);
    Shift::forgetAnchorCache();

    $deviceId = 950000 + random_int(1, 9999);
    DB::table('pulse_devices')->insert([
        'team_id' => $teamId, 'device_id' => $deviceId, 'module' => 'pith', 'status' => 'active',
    ]);

    Shift::create(['team_id' => $teamId, 'name' => 'Day', 'start_time' => '07:00:00', 'end_time' => '07:00:00', 'is_active' => 1]);

    return [$teamId, $deviceId];
}

function modBale(int $deviceId, string $datetime, int $counter): void
{
    DB::table('pulse_device_data_fibre_bales')->insert([
        'device_id' => $deviceId,
        'bale_date' => substr($datetime, 0, 10),
        'bale_datetime' => $datetime,
        'bales_counter' => $counter,
        'eb_units_counter' => $counter,
        'is_duplicate' => 0,
        'shift_id' => null,
    ]);
}

function modBalesExpected(int $teamId, string $date): int
{
    return (int) DB::table('pulse_summary_mini_bales')
        ->where('team_id', $teamId)->where('shift_date', $date)->value('bales_expected');
}

it('carries the counter across an overnight silence', function () {
    $this->travelTo('2026-08-11 09:00:00');
    [$teamId, $deviceId] = modBalesTeam();

    modBale($deviceId, '2026-08-09 21:00:00', 500);   // last reading of the previous shift day
    modBale($deviceId, '2026-08-10 07:30:00', 520);   // twenty bales made overnight
    modBale($deviceId, '2026-08-10 12:00:00', 530);

    (new ProcessDeviceDataForModBalesJob($teamId, $deviceId, ['2026-08-10']))->recompute(['2026-08-10']);

    expect(modBalesExpected($teamId, '2026-08-10'))->toBe(30);
});

it('opens on its own first reading when the silence spanned a whole shift day', function () {
    $this->travelTo('2026-08-11 09:00:00');
    [$teamId, $deviceId] = modBalesTeam();

    modBale($deviceId, '2026-08-08 20:00:00', 500);   // two shift days back
    modBale($deviceId, '2026-08-10 08:00:00', 520);
    modBale($deviceId, '2026-08-10 09:00:00', 523);

    (new ProcessDeviceDataForModBalesJob($teamId, $deviceId, ['2026-08-10']))->recompute(['2026-08-10']);

    // The `last - first + 1` rule, unchanged, for the day that cannot carry.
    expect(modBalesExpected($teamId, '2026-08-10'))->toBe(4);
});

/**
 * A counter that runs backwards sends the day down a second, slower path that walks the
 * readings one by one. That walk used to restart from nothing — dropping the carry-over on
 * precisely the days the fast path could not be trusted.
 */
it('keeps the carried counter when the day falls back to walking a reset', function () {
    $this->travelTo('2026-08-11 09:00:00');
    [$teamId, $deviceId] = modBalesTeam();

    modBale($deviceId, '2026-08-09 21:00:00', 500);
    modBale($deviceId, '2026-08-10 07:30:00', 520);   // twenty made overnight
    modBale($deviceId, '2026-08-10 12:00:00', 5);     // the counter reset

    (new ProcessDeviceDataForModBalesJob($teamId, $deviceId, ['2026-08-10']))->recompute(['2026-08-10']);

    // Twenty carried across the boundary, plus one for the bale that reset the counter.
    expect(modBalesExpected($teamId, '2026-08-10'))->toBe(21);
});
