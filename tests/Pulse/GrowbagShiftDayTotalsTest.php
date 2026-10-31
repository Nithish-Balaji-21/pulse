<?php

use App\Console\Commands\Pulse\GenerateDemoData;
use App\Http\Controllers\Pulse\Dashboard\GrowBags\DashboardGrowBagsApiController;
use App\Jobs\Pulse\ProcessDeviceDataForGrowBagsJob;
use App\Models\Pulse\Shift;
use App\Models\Team;
use App\Models\User;
use App\Services\Pulse\Dashboard\GrowBagsProductionService;
use App\Services\Pulse\GrowbagShiftDayTotals;
use App\Support\Pulse\TeamRolePermissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Pulse\Support\TeamFactory;

/**
 * Growbags print bags, but the device counts punches, and one punch is worth
 * `bags_per_punch` bags on the machine that made it. These cover what that conversion —
 * and the counter behind it — must not do: count a punch on a changeover under both
 * shifts, hand every machine on a shared device the whole device's output, or let the
 * hourly bars be anything other than the same measurement as the figures beside them.
 */
function growbagTotalsTeam(string $anchor = '07:00:00'): array
{
    $teamId = TeamFactory::create('Growbag Totals Factory '.uniqid(), false, [
        'modules' => [
            'pith' => ['is_active' => false, 'submodules' => ['blocks' => false, 'bales' => false]],
            'growbags' => ['is_active' => true],
        ],
    ]);

    DB::table('pulse_settings')->insert([
        'team_id' => $teamId, 'module' => 'shifts', 'key' => Shift::ANCHOR_SETTING_KEY,
        'text' => 'Shift day anchor', 'value' => $anchor,
    ]);
    Shift::forgetAnchorCache();

    $deviceId = 960000 + random_int(1, 9999);
    DB::table('pulse_devices')->insert([
        'team_id' => $teamId, 'device_id' => $deviceId, 'module' => 'growbags', 'status' => 'active',
    ]);

    return [$teamId, $deviceId];
}

function growbagMachine(int $teamId, int $deviceId, string $fieldId, int $bagsPerPunch): int
{
    return (int) DB::table('pulse_master_growbag_machines')->insertGetId([
        'team_id' => $teamId, 'device_id' => $deviceId, 'field_id' => $fieldId,
        'machine_name' => strtoupper($fieldId), 'bags_per_punch' => $bagsPerPunch, 'is_active' => 1,
    ]);
}

function growbagPunch(int $deviceId, string $datetime, int $counter, string $fieldId = 'd1'): void
{
    DB::table('pulse_device_data_growbags')->insert([
        'device_id' => $deviceId,
        'field_id' => $fieldId,
        'growbag_date' => substr($datetime, 0, 10),
        'growbag_datetime' => $datetime,
        'growbags_counter' => $counter,
        'eb_units_counter' => $counter,
        'is_duplicate' => 0,
        'shift_id' => null,
    ]);
}

function growbagAdmin(int $teamId): User
{
    $user = User::factory()->create(['is_superadmin' => 0, 'is_active' => 1, 'current_team_id' => $teamId]);

    DB::table('team_user')->insert(['team_id' => $teamId, 'user_id' => $user->id, 'role' => 'admin']);
    app()->instance('current.team', Team::find($teamId));
    TeamRolePermissions::syncOnModuleChange($teamId);

    return $user;
}

afterEach(function () {
    app()->forgetInstance('current.team');
});

/**
 * The job counted its shift window with both ends inclusive but stamped it start-inclusive
 * and end-exclusive, so a punch on the changeover was summarised under both shifts — and at
 * growbags' multiplier that is `bags_per_punch` bags of phantom production, not one.
 */
it('gives a punch on a shift changeover to one shift only', function () {
    $this->travelTo('2026-08-10 09:00:00');
    [$teamId, $deviceId] = growbagTotalsTeam();
    growbagMachine($teamId, $deviceId, 'd1', 2);

    $day = Shift::create(['team_id' => $teamId, 'name' => 'Day', 'start_time' => '07:00:00', 'end_time' => '18:00:00', 'is_active' => 1]);
    $night = Shift::create(['team_id' => $teamId, 'name' => 'Night', 'start_time' => '18:00:00', 'end_time' => '07:00:00', 'is_active' => 1]);

    growbagPunch($deviceId, '2026-08-09 09:00:00', 10);
    growbagPunch($deviceId, '2026-08-09 18:00:00', 11);   // exactly on the changeover
    growbagPunch($deviceId, '2026-08-09 20:00:00', 12);

    (new ProcessDeviceDataForGrowBagsJob($teamId, $deviceId, ['2026-08-09']))->handle();

    $rows = DB::table('pulse_summary_growbags')->where('shift_date', '2026-08-09')->get()->keyBy('shift_id');

    // Three punches on a two-bag machine is six bags, however they fall across the shifts.
    expect((int) $rows[$day->id]->bags_produced)->toBe(2)
        ->and((int) $rows[$night->id]->bags_produced)->toBe(4)
        ->and($rows->sum('bags_produced'))->toBe(6);
});

/**
 * Each machine keeps its own counter, so each is summarised against its own travel.
 * Differencing one machine's reading against another's — which a single walk over the
 * device's readings does — gave the machine holding the highest sequence everything and the
 * rest nothing at all.
 */
it('credits a punch to the machine that made it, not to every machine on the device', function () {
    $this->travelTo('2026-08-10 09:00:00');
    [$teamId, $deviceId] = growbagTotalsTeam();
    $small = growbagMachine($teamId, $deviceId, 'd1', 2);
    $large = growbagMachine($teamId, $deviceId, 'd2', 3);

    Shift::create(['team_id' => $teamId, 'name' => 'Day', 'start_time' => '07:00:00', 'end_time' => '07:00:00', 'is_active' => 1]);

    growbagPunch($deviceId, '2026-08-09 09:00:00', 1, 'd1');
    growbagPunch($deviceId, '2026-08-09 09:05:00', 1, 'd2');
    growbagPunch($deviceId, '2026-08-09 09:10:00', 2, 'd1');

    (new ProcessDeviceDataForGrowBagsJob($teamId, $deviceId, ['2026-08-09']))->handle();

    $rows = DB::table('pulse_summary_growbags')->where('shift_date', '2026-08-09')->get()->keyBy('growbag_machine_id');

    expect((int) $rows[$small]->bags_produced)->toBe(4)   // two punches at 2 bags
        ->and((int) $rows[$large]->bags_produced)->toBe(3) // one punch at 3 bags
        ->and($rows->sum('bags_produced'))->toBe(7);
});

/**
 * The bars used to be raw row counts scaled to the day's summary total, with the rounding
 * difference dropped on the busiest hour. On a day whose uploads went missing that drew one
 * invented spike — 971 bags in a single hour at one factory — instead of the day's shape.
 */
/**
 * The day view draws a line per machine, so each hour has to carry the split — and it has to
 * be the split its own bar is made of, not a second count of the same hour.
 */
it('splits each hour of the day view by machine, summing to that hour\'s bar', function () {
    $this->travelTo('2026-08-10 09:00:00');
    [$teamId, $deviceId] = growbagTotalsTeam();
    $one = growbagMachine($teamId, $deviceId, 'd1', 1);
    $four = growbagMachine($teamId, $deviceId, 'd2', 4);
    $this->actingAs(growbagAdmin($teamId));

    Shift::create(['team_id' => $teamId, 'name' => 'Day', 'start_time' => '07:00:00', 'end_time' => '07:00:00', 'is_active' => 1]);

    // Each machine on its own counter: d1 makes three punches in the hour, d2 makes two.
    growbagPunch($deviceId, '2026-08-09 08:10:00', 10, 'd1');
    growbagPunch($deviceId, '2026-08-09 08:20:00', 12, 'd1');
    growbagPunch($deviceId, '2026-08-09 08:30:00', 700, 'd2');
    growbagPunch($deviceId, '2026-08-09 08:40:00', 701, 'd2');

    $production = app(GrowBagsProductionService::class)
        ->get(Carbon\Carbon::parse('2026-08-09'), Carbon\Carbon::parse('2026-08-09'), 'day')['growbagsProduction'];

    $hour = collect($production['data'])->firstWhere('period', '8 am - 9 am');
    $byMachine = collect($hour['shifts'])->flatMap(fn ($shift) => $shift['production'])->keyBy('machine_id');

    // d1: three punches at one bag. d2: two punches at four bags.
    expect($byMachine[$one]['growbags'])->toBe(3)
        ->and($byMachine[$four]['growbags'])->toBe(8)
        ->and($hour['day_growbags'])->toBe(11)
        ->and(collect($hour['shifts'])->sum('shift_growbags'))->toBe($hour['day_growbags']);
});

/**
 * The app and the web must answer a request for the same day with the same numbers. They
 * used to be two counts of the same readings — the endpoint took each hour's own min/max
 * span per machine — and they did not agree.
 */
it('gives the app and the web the same day, bag for bag and rupee for rupee', function () {
    $this->travelTo('2026-08-10 09:00:00');
    [$teamId, $deviceId] = growbagTotalsTeam();
    growbagMachine($teamId, $deviceId, 'd1', 2);
    growbagMachine($teamId, $deviceId, 'd2', 3);
    $this->actingAs(growbagAdmin($teamId));

    DB::table('pulse_settings')->insert([
        'team_id' => $teamId, 'module' => 'growbags', 'key' => 'RS_PER_EB_UNIT',
        'text' => 'Rupees per EB unit', 'value' => '9.50',
    ]);

    Shift::create(['team_id' => $teamId, 'name' => 'Day', 'start_time' => '07:00:00', 'end_time' => '19:00:00', 'is_active' => 1]);
    Shift::create(['team_id' => $teamId, 'name' => 'Night', 'start_time' => '19:00:00', 'end_time' => '07:00:00', 'is_active' => 1]);

    growbagPunch($deviceId, '2026-08-09 08:10:00', 10, 'd1');
    growbagPunch($deviceId, '2026-08-09 09:20:00', 14, 'd1');
    growbagPunch($deviceId, '2026-08-09 09:25:00', 500, 'd2');
    growbagPunch($deviceId, '2026-08-09 20:30:00', 18, 'd1');
    growbagPunch($deviceId, '2026-08-09 21:00:00', 505, 'd2');

    $web = app(GrowBagsProductionService::class)
        ->get(Carbon\Carbon::parse('2026-08-09'), Carbon\Carbon::parse('2026-08-09'), 'day')['growbagsProduction'];

    $method = new ReflectionMethod(DashboardGrowBagsApiController::class, 'fetchHourlyData');
    $api = $method->invoke(app(DashboardGrowBagsApiController::class), $teamId, collect([$deviceId]), '2026-08-09', '2026-08-09');

    $webBags = collect($web['data'])->sum('day_growbags');
    $apiBags = collect($api)->sum(fn ($hour) => collect($hour['shifts'])->sum('shift_growbags'));
    $webPrice = round(collect($web['data'])->sum('eb_price'), 1);
    $apiPrice = round(collect($api)->sum(fn ($hour) => collect($hour['shifts'])->sum('shift_eb_price')), 1);

    expect($apiBags)->toBe($webBags)
        ->and($apiPrice)->toBe($webPrice)
        ->and($webBags)->toBeGreaterThan(0);
});

it('measures the hourly bars by counter travel, so no hour is handed a lump it did not earn', function () {
    $this->travelTo('2026-08-10 09:00:00');
    [$teamId, $deviceId] = growbagTotalsTeam();
    growbagMachine($teamId, $deviceId, 'd1', 2);
    $admin = growbagAdmin($teamId);
    $this->actingAs($admin);

    Shift::create(['team_id' => $teamId, 'name' => 'Day', 'start_time' => '07:00:00', 'end_time' => '07:00:00', 'is_active' => 1]);

    // 25 punches made between 8 and 9, of which all but two readings never arrived.
    growbagPunch($deviceId, '2026-08-09 08:10:00', 1);
    growbagPunch($deviceId, '2026-08-09 08:20:00', 25);
    // Five more in the next hour.
    growbagPunch($deviceId, '2026-08-09 09:30:00', 30);

    $production = app(GrowBagsProductionService::class)
        ->get(Carbon\Carbon::parse('2026-08-09'), Carbon\Carbon::parse('2026-08-09'), 'day')['growbagsProduction'];

    $hours = collect($production['data'])->keyBy('period');

    expect($hours['8 am - 9 am']['day_growbags'])->toBe(50)
        ->and($hours['9 am - 10 am']['day_growbags'])->toBe(10)
        ->and(collect($production['data'])->sum('day_growbags'))->toBe(60)
        ->and($production['total_growbags'])->toBe(60);
});

/**
 * A shift the nightly job never summarised was counted by the IoT calendar and not by the
 * dashboard, which is what put the two screens a shift apart on the same day.
 */
it('counts a shift the nightly job never summarised, on both screens alike', function () {
    $this->travelTo('2026-08-10 09:00:00');
    [$teamId, $deviceId] = growbagTotalsTeam();
    $machineId = growbagMachine($teamId, $deviceId, 'd1', 2);
    $admin = growbagAdmin($teamId);

    $day = Shift::create(['team_id' => $teamId, 'name' => 'Day', 'start_time' => '07:00:00', 'end_time' => '18:00:00', 'is_active' => 1]);
    $night = Shift::create(['team_id' => $teamId, 'name' => 'Night', 'start_time' => '18:00:00', 'end_time' => '07:00:00', 'is_active' => 1]);

    // The day shift was summarised; the night shift's readings arrived afterwards.
    DB::table('pulse_summary_growbags')->insert([
        'team_id' => $teamId, 'growbag_machine_id' => $machineId, 'machine_name' => 'D1',
        'shift_date' => '2026-08-09', 'shift_id' => $day->id, 'shift_name' => 'Day',
        'bags_produced' => 40, 'eb_units' => 0, 'eb_price' => 0,
    ]);

    growbagPunch($deviceId, '2026-08-09 19:00:00', 100);
    growbagPunch($deviceId, '2026-08-09 21:00:00', 104);

    $totals = app(GrowbagShiftDayTotals::class)->forDate($teamId, '2026-08-09')->keyBy('shift_id');

    expect($totals[$day->id]->bags_produced)->toBe(40)
        ->and($totals[$night->id]->bags_produced)->toBe(10);   // five punches at two bags

    $dashboard = $this->actingAs($admin)
        ->getJson("/{$teamId}/dashboard/growbags?start=2026-08-09&end=2026-08-09&type=day")
        ->assertOk()->json('data.growbagsProduction');

    expect($dashboard['shiftSummary'][$night->id]['growbags'])->toBe(10)
        ->and($dashboard['total_growbags'])->toBe(50);
});

/**
 * A reading can carry a field_id no machine claims — a stray, or one that arrived before the
 * tag was being stamped. On a device running a single machine it can have come from nowhere
 * else, and dropping it would quietly lose a real punch from the day.
 */
it('credits an untagged punch to the only machine on the device', function () {
    $this->travelTo('2026-08-10 09:00:00');
    [$teamId, $deviceId] = growbagTotalsTeam();
    growbagMachine($teamId, $deviceId, 'd1', 2);

    Shift::create(['team_id' => $teamId, 'name' => 'Day', 'start_time' => '07:00:00', 'end_time' => '07:00:00', 'is_active' => 1]);

    growbagPunch($deviceId, '2026-08-09 09:00:00', 1, 'd1');
    growbagPunch($deviceId, '2026-08-09 09:05:00', 2, '');   // tag never stamped

    (new ProcessDeviceDataForGrowBagsJob($teamId, $deviceId, ['2026-08-09']))->handle();

    expect((int) DB::table('pulse_summary_growbags')->where('shift_date', '2026-08-09')->sum('bags_produced'))->toBe(4);
});

/**
 * The regression this class was rewritten for: a factory's machines each report their own
 * counter, and one machine's sequence sitting above another's is nothing but a different
 * starting point. Walked as though the device kept a single counter, every reading below the
 * running maximum scored zero — so the machine on the highest sequence took the whole
 * factory's output and the others vanished from the summary altogether.
 */
it('measures each machine against its own counter, whatever the others are reading', function () {
    $this->travelTo('2026-08-10 09:00:00');
    [$teamId, $deviceId] = growbagTotalsTeam();
    $low = growbagMachine($teamId, $deviceId, 'd1', 1);
    $high = growbagMachine($teamId, $deviceId, 'd2', 2);

    Shift::create(['team_id' => $teamId, 'name' => 'Day', 'start_time' => '07:00:00', 'end_time' => '07:00:00', 'is_active' => 1]);

    // d2 runs three thousand punches ahead of d1 — a different counter, not more production.
    growbagPunch($deviceId, '2026-08-09 09:00:00', 10, 'd1');
    growbagPunch($deviceId, '2026-08-09 09:01:00', 3000, 'd2');
    growbagPunch($deviceId, '2026-08-09 09:02:00', 11, 'd1');
    growbagPunch($deviceId, '2026-08-09 09:03:00', 3001, 'd2');
    growbagPunch($deviceId, '2026-08-09 09:04:00', 12, 'd1');

    (new ProcessDeviceDataForGrowBagsJob($teamId, $deviceId, ['2026-08-09']))->handle();

    $rows = DB::table('pulse_summary_growbags')->where('shift_date', '2026-08-09')->get()->keyBy('growbag_machine_id');

    // Three punches at one bag, two punches at two bags. Neither machine is zeroed by the other.
    expect((int) $rows[$low]->bags_produced)->toBe(3)
        ->and((int) $rows[$high]->bags_produced)->toBe(4)
        ->and($rows->sum('bags_produced'))->toBe(7);
});

/**
 * Where two machines share a device, the same untagged punch could have come from either, so
 * it is left out of both rather than guessed at. It belongs to no machine's counter, so it
 * does not disturb what either of them is measured to have travelled either.
 */
it('leaves an untagged punch out where two machines share the device', function () {
    $this->travelTo('2026-08-10 09:00:00');
    [$teamId, $deviceId] = growbagTotalsTeam();
    $small = growbagMachine($teamId, $deviceId, 'd1', 2);
    growbagMachine($teamId, $deviceId, 'd2', 3);

    Shift::create(['team_id' => $teamId, 'name' => 'Day', 'start_time' => '07:00:00', 'end_time' => '07:00:00', 'is_active' => 1]);

    growbagPunch($deviceId, '2026-08-09 09:00:00', 1, 'd1');
    growbagPunch($deviceId, '2026-08-09 09:05:00', 77, '');
    growbagPunch($deviceId, '2026-08-09 09:10:00', 2, 'd1');

    (new ProcessDeviceDataForGrowBagsJob($teamId, $deviceId, ['2026-08-09']))->handle();

    $rows = DB::table('pulse_summary_growbags')->where('shift_date', '2026-08-09')->get()->keyBy('growbag_machine_id');

    // Two punches of d1's own, at two bags each — the untagged one is not folded into them.
    expect((int) $rows[$small]->bags_produced)->toBe(4)
        ->and($rows->sum('bags_produced'))->toBe(4);
});

/**
 * The counter does not stop at a changeover, and neither does the machine. Measuring a shift
 * as its own walk — the first punch of the shift worth one, whatever the counter had done
 * since the previous shift's last reading — lost every punch made in between, multiplied by
 * `bags_per_punch` on the way out.
 */
it('counts the punches made between one shift and the next', function () {
    $this->travelTo('2026-08-10 09:00:00');
    [$teamId, $deviceId] = growbagTotalsTeam();
    growbagMachine($teamId, $deviceId, 'd1', 2);

    $day = Shift::create(['team_id' => $teamId, 'name' => 'Day', 'start_time' => '07:00:00', 'end_time' => '18:00:00', 'is_active' => 1]);
    $night = Shift::create(['team_id' => $teamId, 'name' => 'Night', 'start_time' => '18:00:00', 'end_time' => '07:00:00', 'is_active' => 1]);

    growbagPunch($deviceId, '2026-08-09 07:30:00', 100);
    growbagPunch($deviceId, '2026-08-09 17:00:00', 110);
    // Ten punches made between 17:00 and 18:30, with no reading in between: the machine ran
    // through the changeover and the uploads never arrived.
    growbagPunch($deviceId, '2026-08-09 18:30:00', 120);
    growbagPunch($deviceId, '2026-08-09 20:00:00', 123);

    (new ProcessDeviceDataForGrowBagsJob($teamId, $deviceId, ['2026-08-09']))->handle();

    $rows = DB::table('pulse_summary_growbags')
        ->where('team_id', $teamId)->where('shift_date', '2026-08-09')->get()->keyBy('shift_id');

    // Eleven punches to the day shift and thirteen to the night, two bags apiece.
    expect((int) $rows[$day->id]->bags_produced)->toBe(22)
        ->and((int) $rows[$night->id]->bags_produced)->toBe(26)
        ->and((int) $rows->sum('bags_produced'))->toBe(48);
});

/**
 * A punch in a stretch the schedule does not cover is still a punch. It used to be left out
 * of the summary altogether; it now joins the shift it sits closer to, the same placement the
 * raw fallback uses.
 */
it('counts a punch in a shift coverage gap under the nearer shift', function () {
    $this->travelTo('2026-08-10 09:00:00');
    [$teamId, $deviceId] = growbagTotalsTeam();
    growbagMachine($teamId, $deviceId, 'd1', 2);

    $day = Shift::create(['team_id' => $teamId, 'name' => 'Day', 'start_time' => '07:00:00', 'end_time' => '17:00:00', 'is_active' => 1]);
    $night = Shift::create(['team_id' => $teamId, 'name' => 'Night', 'start_time' => '19:00:00', 'end_time' => '07:00:00', 'is_active' => 1]);

    growbagPunch($deviceId, '2026-08-09 10:00:00', 100);
    growbagPunch($deviceId, '2026-08-09 17:30:00', 101);   // in the gap, before its midpoint
    growbagPunch($deviceId, '2026-08-09 18:30:00', 102);   // in the gap, after its midpoint

    (new ProcessDeviceDataForGrowBagsJob($teamId, $deviceId, ['2026-08-09']))->handle();

    $rows = DB::table('pulse_summary_growbags')
        ->where('team_id', $teamId)->where('shift_date', '2026-08-09')->get()->keyBy('shift_id');

    expect((int) $rows[$day->id]->bags_produced)->toBe(4)
        ->and((int) $rows[$night->id]->bags_produced)->toBe(2);
});

/**
 * The day boundary is a changeover like any other. A shift day that opened cold scored its
 * first punch as one punch however far the counter had moved overnight — and at growbags'
 * multiplier that is one punch's worth of bags standing in for a night's work.
 */
it('carries the counter across an overnight silence into the new shift day', function () {
    $this->travelTo('2026-08-11 09:00:00');
    [$teamId, $deviceId] = growbagTotalsTeam();
    growbagMachine($teamId, $deviceId, 'd1', 2);

    $day = Shift::create(['team_id' => $teamId, 'name' => 'Day', 'start_time' => '07:00:00', 'end_time' => '18:00:00', 'is_active' => 1]);
    Shift::create(['team_id' => $teamId, 'name' => 'Night', 'start_time' => '18:00:00', 'end_time' => '07:00:00', 'is_active' => 1]);

    growbagPunch($deviceId, '2026-08-09 21:00:00', 500);   // last reading of the previous shift day
    growbagPunch($deviceId, '2026-08-10 07:30:00', 520);   // twenty punches made overnight

    (new ProcessDeviceDataForGrowBagsJob($teamId, $deviceId, ['2026-08-10']))->handle();

    $rows = DB::table('pulse_summary_growbags')
        ->where('team_id', $teamId)->where('shift_date', '2026-08-10')->get()->keyBy('shift_id');

    expect((int) $rows[$day->id]->bags_produced)->toBe(40);
});

it('does not carry a silence that spanned a whole shift day', function () {
    $this->travelTo('2026-08-11 09:00:00');
    [$teamId, $deviceId] = growbagTotalsTeam();
    growbagMachine($teamId, $deviceId, 'd1', 2);

    $day = Shift::create(['team_id' => $teamId, 'name' => 'Day', 'start_time' => '07:00:00', 'end_time' => '18:00:00', 'is_active' => 1]);
    Shift::create(['team_id' => $teamId, 'name' => 'Night', 'start_time' => '18:00:00', 'end_time' => '07:00:00', 'is_active' => 1]);

    growbagPunch($deviceId, '2026-08-08 20:00:00', 500);   // two shift days back
    growbagPunch($deviceId, '2026-08-10 08:00:00', 520);

    (new ProcessDeviceDataForGrowBagsJob($teamId, $deviceId, ['2026-08-10']))->handle();

    $rows = DB::table('pulse_summary_growbags')
        ->where('team_id', $teamId)->where('shift_date', '2026-08-10')->get()->keyBy('shift_id');

    expect((int) $rows[$day->id]->bags_produced)->toBe(2);
});

/**
 * Energy was the last figure still measured a bucket at a time — each shift taking the span
 * of its own readings, which invented a unit per shift and dropped whatever the meter did
 * between them. It rides the same walk as the punches now.
 */
it('measures energy by the same walk as the punches, so the shifts add up to the day', function () {
    $this->travelTo('2026-08-10 09:00:00');
    [$teamId, $deviceId] = growbagTotalsTeam();
    growbagMachine($teamId, $deviceId, 'd1', 2);

    $day = Shift::create(['team_id' => $teamId, 'name' => 'Day', 'start_time' => '07:00:00', 'end_time' => '18:00:00', 'is_active' => 1]);
    $night = Shift::create(['team_id' => $teamId, 'name' => 'Night', 'start_time' => '18:00:00', 'end_time' => '07:00:00', 'is_active' => 1]);

    growbagPunch($deviceId, '2026-08-09 07:30:00', 100);
    growbagPunch($deviceId, '2026-08-09 17:00:00', 110);
    growbagPunch($deviceId, '2026-08-09 18:30:00', 120);   // the meter ran through the changeover

    (new ProcessDeviceDataForGrowBagsJob($teamId, $deviceId, ['2026-08-09']))->handle();

    $rows = DB::table('pulse_summary_growbags')
        ->where('team_id', $teamId)->where('shift_date', '2026-08-09')->get()->keyBy('shift_id');

    // The ten units the meter travelled across the changeover belong to the night shift,
    // not one invented unit — and the two shifts sum to the day's travel.
    expect((float) $rows[$day->id]->eb_units)->toBe(11.0)
        ->and((float) $rows[$night->id]->eb_units)->toBe(10.0);
});

/**
 * A growbag factory that closes on Sundays leaves its device silent for more than a shift
 * day, and Monday's walk declines to carry the counter across — which is the rule, and the
 * warning that names what went uncarried is how an operator settles it by hand.
 *
 * That warning reported the wrong travel. Each machine keeps its own counter, but the
 * resumption it differenced against was the device's next reading whichever machine made
 * it, so a machine seeded at 1,000,000 was measured against the one seeded at 2,000,000 and
 * the log claimed a million punches — or, the other way round, minus a million.
 */
it('measures a declined carry-over against the same machine it was seeded from', function () {
    $this->travelTo('2026-08-10 12:00:00');
    [$teamId, $deviceId] = growbagTotalsTeam();
    growbagMachine($teamId, $deviceId, 'd1', 2);
    growbagMachine($teamId, $deviceId, 'd2', 2);

    Shift::create(['team_id' => $teamId, 'name' => 'Day', 'start_time' => '07:00:00', 'end_time' => '18:00:00', 'is_active' => 1]);

    // Counters are kept far apart so that no two machines' readings can ever collide.
    growbagPunch($deviceId, '2026-08-08 09:00:00', 1000000, 'd1');
    growbagPunch($deviceId, '2026-08-08 09:05:00', 2000000, 'd2');

    // Sunday: nothing from either machine. On Monday d2 punches first.
    growbagPunch($deviceId, '2026-08-10 07:30:00', 2000001, 'd2');
    growbagPunch($deviceId, '2026-08-10 08:00:00', 1000003, 'd1');

    $logged = [];
    Log::listen(function ($message) use (&$logged) {
        if ($message->level === 'warning' && str_starts_with($message->message, 'Counter carry-over declined')) {
            $logged[] = $message->context;
        }
    });

    (new ProcessDeviceDataForGrowBagsJob($teamId, $deviceId))->recompute(['2026-08-10']);

    $punchWarnings = collect($logged)->where('field_id', 'd1')->values();

    expect($punchWarnings)->toHaveCount(1)
        ->and($punchWarnings[0]['resumed_at'])->toBe('2026-08-10 08:00:00')
        ->and($punchWarnings[0]['counter_travel']['punches'])->toBe(3.0);
});

/**
 * A factory that closes on Sunday leaves a device powered but idle, and an idle device still
 * reports — the same counter it reported yesterday. That one flat reading is what keeps the
 * chain of shift days unbroken, so Monday seeds from Sunday and the counter travel over the
 * shutdown is measured rather than scored as a bare first reading.
 *
 * The demo generator writes nothing at all on an off day, which is what made the device look
 * dead for 26 hours every Monday; {@see GenerateDemoData::idleHeartbeat()}
 * is the other half of this.
 */
it('carries the counter across an idle day that reported a flat heartbeat', function () {
    $this->travelTo('2026-08-11 09:00:00');
    [$teamId, $deviceId] = growbagTotalsTeam();
    growbagMachine($teamId, $deviceId, 'd1', 1);

    $day = Shift::create(['team_id' => $teamId, 'name' => 'Day', 'start_time' => '07:00:00', 'end_time' => '18:00:00', 'is_active' => 1]);

    growbagPunch($deviceId, '2026-08-08 09:00:00', 100);   // Saturday
    growbagPunch($deviceId, '2026-08-09 12:00:00', 100);   // Sunday, idle: the counter stands still
    growbagPunch($deviceId, '2026-08-10 08:00:00', 105);   // Monday, five punches on since Saturday
    growbagPunch($deviceId, '2026-08-10 09:00:00', 106);

    (new ProcessDeviceDataForGrowBagsJob($teamId, $deviceId))->recompute(['2026-08-09', '2026-08-10']);

    $rows = DB::table('pulse_summary_growbags')->where('team_id', $teamId)->get()->keyBy('shift_date');

    // Cold, Monday would have scored its first reading as a single punch and reported 2.
    expect((int) $rows['2026-08-10']->bags_produced)->toBe(6)
        ->and($rows->has('2026-08-09'))->toBeFalse();   // an idle day produced nothing
});
