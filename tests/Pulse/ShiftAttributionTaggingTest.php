<?php

use App\Jobs\Pulse\ProcessDeviceDataForFibreJob;
use App\Models\Pulse\Shift;
use Illuminate\Support\Facades\DB;
use Tests\Pulse\Support\TeamFactory;

function pulseTaggingTeam(string $anchor = '08:00:00'): int
{
    $teamId = TeamFactory::create('Tagging Test Factory '.uniqid());

    DB::table('pulse_settings')->insert([
        'team_id' => $teamId,
        'module' => 'shifts',
        'key' => Shift::ANCHOR_SETTING_KEY,
        'text' => 'Shift day anchor (start of the 24-hour production day)',
        'value' => $anchor,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $teamId;
}

function insertFibreBaleReading(int $deviceId, string $datetime, int $counter): int
{
    return (int) DB::table('pulse_device_data_fibre_bales')->insertGetId([
        'device_id' => $deviceId,
        'bale_date' => substr($datetime, 0, 10),
        'bale_datetime' => $datetime,
        'bales_counter' => $counter,
        'is_duplicate' => 0,
        'eb_units_counter' => $counter,
        'shift_id' => null,
        'created_at' => $datetime,
        'updated_at' => $datetime,
    ]);
}

beforeEach(function () {
    Shift::forgetAnchorCache();
    $this->deviceId = 990000 + random_int(1, 9999);
});

it('tags a reading at a shift boundary with the later shift', function () {
    $teamId = pulseTaggingTeam();

    $dayShift = Shift::create(['team_id' => $teamId, 'name' => 'Day', 'start_time' => '08:00:00', 'end_time' => '18:00:00', 'is_active' => 1]);
    $nightShift = Shift::create(['team_id' => $teamId, 'name' => 'Night', 'start_time' => '18:00:00', 'end_time' => '08:00:00', 'is_active' => 1]);

    $morningReading = insertFibreBaleReading($this->deviceId, '2026-07-10 10:00:00', 5);
    $boundaryReading = insertFibreBaleReading($this->deviceId, '2026-07-10 18:00:00', 7);
    $overnightReading = insertFibreBaleReading($this->deviceId, '2026-07-11 01:00:00', 9);

    (new ProcessDeviceDataForFibreJob($teamId, $this->deviceId, ['2026-07-10']))->handle();

    expect((int) DB::table('pulse_device_data_fibre_bales')->where('id', $morningReading)->value('shift_id'))->toBe($dayShift->id)
        ->and((int) DB::table('pulse_device_data_fibre_bales')->where('id', $boundaryReading)->value('shift_id'))->toBe($nightShift->id)
        ->and((int) DB::table('pulse_device_data_fibre_bales')->where('id', $overnightReading)->value('shift_id'))->toBe($nightShift->id);
});

/**
 * A reading in a stretch the schedule does not cover is still a bale someone made. It used
 * to be left untagged and out of the summary, which dropped it from every screen the summary
 * owns; it now joins the shift it sits closer to — the same placement the raw fallback in
 * FibreShiftDayTotals uses, so both count it under the same shift.
 */
it('tags a reading in a coverage gap with the nearer shift', function () {
    $teamId = pulseTaggingTeam();

    $dayShift = Shift::create(['team_id' => $teamId, 'name' => 'Day', 'start_time' => '08:00:00', 'end_time' => '18:00:00', 'is_active' => 1]);
    $nightShift = Shift::create(['team_id' => $teamId, 'name' => 'Night', 'start_time' => '20:00:00', 'end_time' => '08:00:00', 'is_active' => 1]);

    $coveredReading = insertFibreBaleReading($this->deviceId, '2026-07-10 10:00:00', 5);
    $beforeMidpoint = insertFibreBaleReading($this->deviceId, '2026-07-10 18:30:00', 6);
    $afterMidpoint = insertFibreBaleReading($this->deviceId, '2026-07-10 19:30:00', 7);

    (new ProcessDeviceDataForFibreJob($teamId, $this->deviceId, ['2026-07-10']))->handle();

    expect((int) DB::table('pulse_device_data_fibre_bales')->where('id', $coveredReading)->value('shift_id'))->toBe($dayShift->id)
        ->and((int) DB::table('pulse_device_data_fibre_bales')->where('id', $beforeMidpoint)->value('shift_id'))->toBe($dayShift->id)
        ->and((int) DB::table('pulse_device_data_fibre_bales')->where('id', $afterMidpoint)->value('shift_id'))->toBe($nightShift->id);

    // And the bales in the gap reach the summary rather than vanishing from it.
    $rows = DB::table('pulse_summary_fibre_mega_bales')
        ->where('team_id', $teamId)->where('shift_date', '2026-07-10')->get()->keyBy('shift_id');

    expect((int) $rows[$dayShift->id]->bales_produced)->toBe(2)
        ->and((int) $rows[$nightShift->id]->bales_produced)->toBe(1);
});
