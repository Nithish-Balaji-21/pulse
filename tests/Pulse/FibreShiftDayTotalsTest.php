<?php

use App\Jobs\Pulse\ProcessDeviceDataForFibreJob;
use App\Models\Pulse\Shift;
use App\Models\Team;
use App\Models\User;
use App\Services\Pulse\Dashboard\FibreProductionService;
use App\Services\Pulse\FibreShiftDayTotals;
use App\Support\Pulse\TeamRolePermissions;
use Illuminate\Support\Facades\DB;
use Tests\Pulse\Support\TeamFactory;

/**
 * The IoT Insights calendars and the dashboard's day view print the same shifts, and used
 * to count them separately: one from the nightly summary, the other from raw device rows.
 * These cover the three ways that let them disagree — a reading on a shift changeover
 * counted twice, a shift the nightly job never summarised counted by only one of them, and
 * bales counted as rows on one screen and as counter travel on the other.
 */
function fibreTotalsTeam(string $anchor = '07:00:00'): array
{
    $teamId = TeamFactory::create('Fibre Totals Factory '.uniqid(), false, [
        'modules' => [
            'pith' => ['is_active' => false, 'submodules' => ['blocks' => false, 'bales' => false]],
            'fibre' => ['is_active' => true, 'submodules' => ['weight' => true]],
        ],
    ]);

    DB::table('pulse_settings')->insert([
        'team_id' => $teamId, 'module' => 'shifts', 'key' => Shift::ANCHOR_SETTING_KEY,
        'text' => 'Shift day anchor', 'value' => $anchor,
    ]);
    Shift::forgetAnchorCache();

    $deviceId = 970000 + random_int(1, 9999);
    DB::table('pulse_devices')->insert([
        'team_id' => $teamId, 'device_id' => $deviceId, 'module' => 'fibre', 'status' => 'active',
    ]);

    $day = Shift::create(['team_id' => $teamId, 'name' => 'Day', 'start_time' => '07:00:00', 'end_time' => '18:00:00', 'is_active' => 1]);
    $night = Shift::create(['team_id' => $teamId, 'name' => 'Night', 'start_time' => '18:00:00', 'end_time' => '07:00:00', 'is_active' => 1]);

    return [$teamId, $deviceId, $day, $night];
}

function fibreTotalsAdmin(int $teamId): User
{
    $user = User::factory()->create(['is_superadmin' => 0, 'is_active' => 1, 'current_team_id' => $teamId]);

    DB::table('team_user')->insert(['team_id' => $teamId, 'user_id' => $user->id, 'role' => 'admin']);
    app()->instance('current.team', Team::find($teamId));
    TeamRolePermissions::syncOnModuleChange($teamId);

    return $user;
}

function fibreBale(int $deviceId, string $datetime, int $counter, float $ebCounter = 0): void
{
    DB::table('pulse_device_data_fibre_bales')->insert([
        'device_id' => $deviceId,
        'bale_date' => substr($datetime, 0, 10),
        'bale_datetime' => $datetime,
        'bales_counter' => $counter,
        'is_duplicate' => 0,
        'eb_units_counter' => $ebCounter ?: $counter,
        'shift_id' => null,
    ]);
}

function fibreWeighing(int $deviceId, string $datetime, float $weight): void
{
    DB::table('pulse_device_data_fibre_mega_bales_weight')->insert([
        'device_id' => $deviceId,
        'bale_weight_date' => substr($datetime, 0, 10),
        'bale_weight_datetime' => $datetime,
        'bale_weight' => $weight,
        'shift_id' => null,
    ]);
}

afterEach(function () {
    app()->forgetInstance('current.team');
});

/**
 * The job counted its shift window with both ends inclusive but stamped it start-inclusive
 * and end-exclusive, so a bale weighed at the moment of the changeover was summarised under
 * both shifts — a day whose total then stood one bale above the readings it was built from.
 */
it('gives a reading on a shift changeover to one shift only', function () {
    $this->travelTo('2026-08-10 09:00:00');
    [$teamId, $deviceId, $day, $night] = fibreTotalsTeam();

    fibreWeighing($deviceId, '2026-08-09 09:00:00', 118);
    fibreWeighing($deviceId, '2026-08-09 18:00:00', 122);   // exactly on the changeover
    fibreWeighing($deviceId, '2026-08-09 20:00:00', 125);

    fibreBale($deviceId, '2026-08-09 09:00:00', 10);
    fibreBale($deviceId, '2026-08-09 18:00:00', 11);
    fibreBale($deviceId, '2026-08-09 20:00:00', 12);

    (new ProcessDeviceDataForFibreJob($teamId, $deviceId, ['2026-08-09']))->handle();

    $rows = DB::table('pulse_summary_fibre_mega_bales')->where('shift_date', '2026-08-09')->get()->keyBy('shift_id');

    expect((int) $rows[$day->id]->bales_weighed)->toBe(1)
        ->and((int) $rows[$night->id]->bales_weighed)->toBe(2)
        ->and($rows->sum('bales_weighed'))->toBe(3.0)
        ->and($rows->sum('bales_produced'))->toBe(3);

    // The changeover reading is stamped for the shift that counted it, and no other.
    expect(DB::table('pulse_device_data_fibre_mega_bales_weight')
        ->where('bale_weight_datetime', '2026-08-09 18:00:00')->value('shift_id'))->toBe($night->id);
});

/**
 * The nightly job can leave a shift unsummarised — it has not run yet, or it ran before the
 * shift's readings arrived. The IoT calendar counted those readings and the dashboard did
 * not, which is what put the two screens a shift apart on the same day.
 */
it('counts a shift the nightly job never summarised, on both screens alike', function () {
    $this->travelTo('2026-08-10 09:00:00');
    [$teamId, $deviceId, $day, $night] = fibreTotalsTeam();
    $admin = fibreTotalsAdmin($teamId);

    // The day shift was summarised; the night shift's readings arrived afterwards.
    DB::table('pulse_summary_fibre_mega_bales')->insert([
        'team_id' => $teamId, 'device_id' => $deviceId, 'shift_date' => '2026-08-09',
        'shift_id' => $day->id, 'shift_name' => $day->name,
        'bales_produced' => 40, 'bales_weighed' => 38, 'weight' => 38 * 120, 'eb_units' => 0, 'eb_price' => 0,
    ]);

    fibreWeighing($deviceId, '2026-08-09 19:00:00', 120);
    fibreWeighing($deviceId, '2026-08-09 21:00:00', 130);

    $totals = app(FibreShiftDayTotals::class)->forDate($teamId, '2026-08-09')->keyBy('shift_id');

    expect($totals[$day->id]->bales_weighed)->toBe(38)
        ->and($totals[$night->id]->bales_weighed)->toBe(2);

    // The IoT Insights weight calendar and the dashboard now read that same resolver.
    $calendar = $this->actingAs($admin)
        ->getJson("/{$teamId}/iot-fibre-weight/fetch-events?start=2026-08-09&end=2026-08-09")
        ->assertOk()->json('totals');

    $dashboard = $this->actingAs($admin)
        ->getJson("/{$teamId}/dashboard/fibre?start=2026-08-09&end=2026-08-09&type=day")
        ->assertOk()->json('data.fibreProduction');

    expect($calendar['Night']['total_bales_weighed'])->toBe(2)
        ->and($dashboard['shiftSummary'][$night->id]['bales_weighed'])->toBe(2)
        ->and($dashboard['total_bales_weighed'])->toBe(40);
});

/**
 * A bale is what the machine's counter says it made, not how many rows reached us: an upload
 * that never arrived costs a row, and the nightly summary is built from the counter either
 * way. The dashboard's hourly bars used to count rows, so they read short of every figure
 * printed beside them.
 */
it('measures the hourly bars by counter travel, so they add up to the day', function () {
    $this->travelTo('2026-08-10 09:00:00');
    [$teamId, $deviceId] = fibreTotalsTeam();
    $this->actingAs(fibreTotalsAdmin($teamId));

    // Nine bales made between 08:00 and 09:00, of which two readings never arrived.
    fibreBale($deviceId, '2026-08-09 08:10:00', 1);
    fibreBale($deviceId, '2026-08-09 08:20:00', 2);
    fibreBale($deviceId, '2026-08-09 08:40:00', 5);
    fibreBale($deviceId, '2026-08-09 08:50:00', 9);
    // Three more in the next hour.
    fibreBale($deviceId, '2026-08-09 09:30:00', 12);

    $production = app(FibreProductionService::class)->get('2026-08-09');
    $hours = collect($production['data'])->keyBy('period');

    expect($hours['8 am - 9 am']['bales_produced'])->toBe(9)
        ->and($hours['9 am - 10 am']['bales_produced'])->toBe(3)
        ->and(collect($production['data'])->sum('bales_produced'))->toBe(12)
        ->and($production['total_bales_produced'])->toEqual(12);
});

/**
 * Readings the job has already stamped and summarised must not be counted a second time by
 * the raw fallback, even when the shift they were stamped for is no longer running: their
 * time of day now falls inside a different shift's hours.
 */
it('does not re-count readings summarised under a shift since retired', function () {
    $this->travelTo('2026-08-10 09:00:00');
    [$teamId, $deviceId, $day, $night] = fibreTotalsTeam();

    DB::table('pulse_summary_fibre_mega_bales')->insert([
        'team_id' => $teamId, 'device_id' => $deviceId, 'shift_date' => '2026-08-09',
        'shift_id' => $night->id, 'shift_name' => $night->name,
        'bales_produced' => 2, 'bales_weighed' => 2, 'weight' => 240, 'eb_units' => 0, 'eb_price' => 0,
    ]);

    foreach (['2026-08-09 19:00:00', '2026-08-09 21:00:00'] as $moment) {
        fibreWeighing($deviceId, $moment, 120);
    }
    DB::table('pulse_device_data_fibre_mega_bales_weight')->update(['shift_id' => $night->id]);

    // The night shift is retired, so its hours now belong to the day shift.
    $night->update(['is_active' => 0]);
    $day->update(['end_time' => '07:00:00']);

    $totals = app(FibreShiftDayTotals::class)->forDate($teamId, '2026-08-09');

    expect($totals->sum('bales_weighed'))->toBe(2);
});

/**
 * The counter does not stop at a changeover, and neither does the machine. Measuring a shift
 * as the span of its own readings — max minus min, plus one for the bale that opened it —
 * credited it a single bale for everything made since the previous shift's last reading, so
 * a run through the changeover, or an upload lost across one, disappeared from the summary.
 */
it('counts the bales made between one shift and the next', function () {
    $this->travelTo('2026-08-10 09:00:00');
    [$teamId, $deviceId, $day, $night] = fibreTotalsTeam();

    fibreBale($deviceId, '2026-08-09 07:30:00', 100);
    fibreBale($deviceId, '2026-08-09 17:00:00', 110);
    // Ten bales made between 17:00 and 18:30, with no reading in between: the machine ran
    // through the changeover and the uploads never arrived.
    fibreBale($deviceId, '2026-08-09 18:30:00', 120);
    fibreBale($deviceId, '2026-08-09 20:00:00', 123);

    (new ProcessDeviceDataForFibreJob($teamId, $deviceId, ['2026-08-09']))->handle();

    $rows = DB::table('pulse_summary_fibre_mega_bales')
        ->where('team_id', $teamId)->where('shift_date', '2026-08-09')->get()->keyBy('shift_id');

    // The stretch across the changeover belongs to the shift it ended in.
    expect((int) $rows[$day->id]->bales_produced)->toBe(11)
        ->and((int) $rows[$night->id]->bales_produced)->toBe(13)
        // And the shifts still add up to the day the counter travelled.
        ->and((int) $rows->sum('bales_produced'))->toBe(24);
});

/**
 * The dashboard reads raw readings until the shift day is over and the nightly summary takes
 * over. Both must measure a changeover the same way, or the day's figure moves when the job
 * runs.
 */
it('splits a changeover the same way live as the nightly summary does', function () {
    $this->travelTo('2026-08-09 21:00:00');
    [$teamId, $deviceId, $day, $night] = fibreTotalsTeam();

    fibreBale($deviceId, '2026-08-09 07:30:00', 100);
    fibreBale($deviceId, '2026-08-09 17:00:00', 110);
    fibreBale($deviceId, '2026-08-09 18:30:00', 120);
    fibreBale($deviceId, '2026-08-09 20:00:00', 123);

    $live = app(FibreShiftDayTotals::class)->forDate($teamId, '2026-08-09')->keyBy('shift_id');

    expect($live[$day->id]->bales_produced)->toBe(11)
        ->and($live[$night->id]->bales_produced)->toBe(13);
});

/**
 * The counter does not stop at midnight either. A shift day that opens cold scores its first
 * reading as one bale however far the counter moved overnight, so a machine that ran through
 * the small hours while its uploads failed lost all but one of those bales — 134 of them on
 * one real night. The walk is seeded from the last reading before the day.
 */
it('carries the counter across an overnight silence into the new shift day', function () {
    $this->travelTo('2026-08-11 09:00:00');
    [$teamId, $deviceId, $day] = fibreTotalsTeam();

    fibreBale($deviceId, '2026-08-09 21:00:00', 500);   // last reading of the previous shift day
    // Twenty bales made overnight with no reading to show for them.
    fibreBale($deviceId, '2026-08-10 07:30:00', 520);
    fibreBale($deviceId, '2026-08-10 12:00:00', 530);

    (new ProcessDeviceDataForFibreJob($teamId, $deviceId, ['2026-08-10']))->handle();

    $rows = DB::table('pulse_summary_fibre_mega_bales')
        ->where('team_id', $teamId)->where('shift_date', '2026-08-10')->get()->keyBy('shift_id');

    expect((int) $rows[$day->id]->bales_produced)->toBe(30);
});

it('carries the same overnight silence on the raw path, before the job has run', function () {
    $this->travelTo('2026-08-11 09:00:00');
    [$teamId, $deviceId, $day] = fibreTotalsTeam();

    fibreBale($deviceId, '2026-08-09 21:00:00', 500);
    fibreBale($deviceId, '2026-08-10 07:30:00', 520);
    fibreBale($deviceId, '2026-08-10 12:00:00', 530);

    $totals = app(FibreShiftDayTotals::class)->forDate($teamId, '2026-08-10')->keyBy('shift_id');

    // The same 30 the nightly job settles on, so the figure does not move when it runs.
    expect($totals[$day->id]->bales_produced)->toBe(30)
        // And the reading carried in from 2026-08-09 reports no day of its own.
        ->and(app(FibreShiftDayTotals::class)->forDate($teamId, '2026-08-10')->pluck('shift_date')->unique()->all())
        ->toBe(['2026-08-10']);
});

/**
 * A silence that spanned a whole shift day is a device outage, not one night's work: the
 * counter may have travelled over several days and there is no honest way to put all of it
 * on the day the readings came back. Those are left uncarried and logged instead.
 */
it('does not carry a silence that spanned a whole shift day', function () {
    $this->travelTo('2026-08-11 09:00:00');
    [$teamId, $deviceId, $day] = fibreTotalsTeam();

    fibreBale($deviceId, '2026-08-08 20:00:00', 500);   // two shift days back
    fibreBale($deviceId, '2026-08-10 08:00:00', 520);
    fibreBale($deviceId, '2026-08-10 09:00:00', 523);

    (new ProcessDeviceDataForFibreJob($teamId, $deviceId, ['2026-08-10']))->handle();

    $rows = DB::table('pulse_summary_fibre_mega_bales')
        ->where('team_id', $teamId)->where('shift_date', '2026-08-10')->get()->keyBy('shift_id');

    // One for the opening reading plus the three that followed it — the twenty made during
    // the outage are not invented onto this day.
    expect((int) $rows[$day->id]->bales_produced)->toBe(4)
        ->and(app(FibreShiftDayTotals::class)->forDate($teamId, '2026-08-10')->firstWhere('shift_id', $day->id)->bales_produced)->toBe(4);
});

/**
 * The raw path walks a whole date range in one pass, so every boundary inside the range has
 * to carry too — not just the first.
 */
it('carries every boundary inside a range, so a range equals its days added up', function () {
    $this->travelTo('2026-08-13 09:00:00');
    [$teamId, $deviceId] = fibreTotalsTeam();

    fibreBale($deviceId, '2026-08-09 20:00:00', 100);
    fibreBale($deviceId, '2026-08-10 09:00:00', 130);   // carries 30 across the boundary
    fibreBale($deviceId, '2026-08-11 09:00:00', 160);   // carries 30 across the next one
    fibreBale($deviceId, '2026-08-12 09:00:00', 190);

    $service = app(FibreShiftDayTotals::class);
    $range = $service->forRange($teamId, '2026-08-10', '2026-08-12')->sum('bales_produced');
    $days = collect(['2026-08-10', '2026-08-11', '2026-08-12'])
        ->sum(fn (string $date) => $service->forDate($teamId, $date)->sum('bales_produced'));

    expect($range)->toBe(90)
        ->and($days)->toBe(90);
});

/**
 * The device's uploads do not always reach us in the order the bales were made: one reading
 * overtakes another and the counter appears to step backwards by a few. Every such straggler
 * used to be read as the machine restarting — worth a bale of its own, and worth the whole
 * drop again when the counter caught back up. One real day of 338 readings was inflated from
 * 338 bales to 578 that way.
 */
it('does not count a reading that arrived out of order as production', function () {
    $this->travelTo('2026-08-11 09:00:00');
    [$teamId, $deviceId, $day] = fibreTotalsTeam();

    fibreBale($deviceId, '2026-08-10 08:00:00', 100);
    fibreBale($deviceId, '2026-08-10 08:01:00', 109);
    fibreBale($deviceId, '2026-08-10 08:02:00', 104);   // overtaken, already counted
    fibreBale($deviceId, '2026-08-10 08:03:00', 106);   // still behind the high-water mark
    fibreBale($deviceId, '2026-08-10 08:04:00', 110);

    (new ProcessDeviceDataForFibreJob($teamId, $deviceId, ['2026-08-10']))->handle();

    $summary = (int) DB::table('pulse_summary_fibre_mega_bales')
        ->where('team_id', $teamId)->where('shift_date', '2026-08-10')->sum('bales_produced');

    // The counter reached 110 from 100, plus the bale that opened the day: 11.
    expect($summary)->toBe(11)
        ->and(app(FibreShiftDayTotals::class)->forDate($teamId, '2026-08-10')->firstWhere('shift_id', $day->id)->bales_produced)
        ->toBe(11);
});

/**
 * A machine that genuinely restarts is a different thing, and still counts: its counter goes
 * back to zero, not back by three.
 */
it('still counts a machine whose counter restarted', function () {
    $this->travelTo('2026-08-11 09:00:00');
    [$teamId, $deviceId, $day] = fibreTotalsTeam();

    fibreBale($deviceId, '2026-08-10 08:00:00', 500);
    fibreBale($deviceId, '2026-08-10 09:00:00', 510);
    fibreBale($deviceId, '2026-08-10 10:00:00', 0);     // restarted
    fibreBale($deviceId, '2026-08-10 11:00:00', 4);

    (new ProcessDeviceDataForFibreJob($teamId, $deviceId, ['2026-08-10']))->handle();

    $summary = (int) DB::table('pulse_summary_fibre_mega_bales')
        ->where('team_id', $teamId)->where('shift_date', '2026-08-10')->sum('bales_produced');

    // One to open the day, ten to 510, one for the bale that restarted it, four after.
    expect($summary)->toBe(16)
        ->and(app(FibreShiftDayTotals::class)->forDate($teamId, '2026-08-10')->firstWhere('shift_id', $day->id)->bales_produced)
        ->toBe(16);
});
