<?php

use App\Models\Pulse\Shift;
use App\Models\Team;
use App\Models\User;
use App\Support\Pulse\TeamRolePermissions;
use Illuminate\Support\Facades\DB;
use Tests\Pulse\Support\TeamFactory;

/**
 * The growbag dashboard mirrors the fibre one: KPI tiles above chart cards, each card owning a
 * heading and an empty state. These are smoke tests — that the page renders, that the chips
 * adapt to the shift count, and that a day's shift figures come from the nightly summary rather
 * than being recomputed from raw device rows.
 */
function makeGrowbagTeam(string $name): int
{
    return TeamFactory::create($name, false, [
        'modules' => [
            'pith' => ['is_active' => false, 'submodules' => ['blocks' => false, 'bales' => false]],
            'fibre' => ['is_active' => false, 'submodules' => ['weight' => false]],
            'growbags' => ['is_active' => true],
            'husks_stock' => ['is_active' => false],
            'attendance' => ['is_active' => true],
        ],
    ]);
}

function makeGrowbagAdmin(int $teamId): User
{
    $user = User::factory()->create([
        'is_superadmin' => 0,
        'is_active' => 1,
        'current_team_id' => $teamId,
    ]);

    DB::table('team_user')->insert([
        'team_id' => $teamId,
        'user_id' => $user->id,
        'role' => 'admin',
    ]);

    app()->instance('current.team', Team::find($teamId));
    TeamRolePermissions::syncOnModuleChange($teamId);

    return $user;
}

afterEach(function () {
    app()->forgetInstance('current.team');
});

it('renders the growbag dashboard with its summary tiles and chart cards', function () {
    $teamId = makeGrowbagTeam('Growbag Dashboard Factory '.uniqid());
    $admin = makeGrowbagAdmin($teamId);

    Shift::insert([
        ['team_id' => $teamId, 'device_id' => 0, 'name' => 'Shift 1', 'start_time' => '07:00:00', 'end_time' => '18:00:00', 'is_active' => 1],
        ['team_id' => $teamId, 'device_id' => 0, 'name' => 'Shift 2', 'start_time' => '18:00:00', 'end_time' => '07:00:00', 'is_active' => 1],
    ]);

    $response = $this->actingAs($admin)
        ->get("/{$teamId}/dashboard/growbags")
        ->assertSuccessful();

    // Every tile the AJAX response populates must exist in the first paint.
    foreach (['kpiBagsProduced', 'kpiEbCost'] as $tile) {
        $response->assertSee($tile, false);
    }

    // Each chart card owns a mount, a heading and an empty state.
    foreach (['bagsChart', 'ebChart'] as $chart) {
        $response->assertSee($chart, false);
        $response->assertSee($chart.'Title', false);
        $response->assertSee($chart.'Empty', false);
    }

    $response->assertSee('loading-overlay', false);
    $response->assertSee('dashboard-error', false);

    // Every tile carries a chip per shift.
    foreach (['bags', 'eb'] as $metric) {
        expect(substr_count($response->getContent(), 'data-shift-metric="'.$metric.'"'))->toBe(2);
    }
    $response->assertSee('Shift 1', false);
    $response->assertSee('Shift 2', false);
});

it('drops the shift chips for a single-shift factory, where they would only repeat the total', function () {
    $teamId = makeGrowbagTeam('Growbag Single Shift Factory '.uniqid());
    $admin = makeGrowbagAdmin($teamId);

    Shift::insert([
        ['team_id' => $teamId, 'device_id' => 0, 'name' => 'General', 'start_time' => '07:00:00', 'end_time' => '19:00:00', 'is_active' => 1],
    ]);

    $response = $this->actingAs($admin)
        ->get("/{$teamId}/dashboard/growbags")
        ->assertSuccessful()
        ->assertSee('kpiBagsProduced', false);

    foreach (['bags', 'eb'] as $metric) {
        $response->assertDontSee('data-shift-metric="'.$metric.'"', false);
    }
});

/**
 * The per-machine line chart and its toggles only exist for a factory running more than one
 * machine — with a single machine it would repeat the headline chart line for line.
 */
it('hides the per-machine chart for a single-machine factory', function () {
    $teamId = makeGrowbagTeam('Growbag Single Machine Factory '.uniqid());
    $admin = makeGrowbagAdmin($teamId);

    DB::table('pulse_master_growbag_machines')->insert([
        'team_id' => $teamId, 'device_id' => 70000 + $teamId, 'field_id' => 'd90',
        'machine_name' => 'M1', 'bags_per_punch' => 2, 'is_active' => 1,
    ]);

    $this->actingAs($admin)
        ->get("/{$teamId}/dashboard/growbags")
        ->assertSuccessful()
        ->assertDontSee('machineCheckboxes', false)
        ->assertDontSee('id="lineChart"', false)
        // The table's only row would repeat the tiles above it. Asserted on the mount rather
        // than the bare id, which the script still names.
        ->assertDontSee('id="summaryTableContainer"', false)
        ->assertDontSee('Machine Summary', false);
});

it('shows the per-machine chart once a second machine exists', function () {
    $teamId = makeGrowbagTeam('Growbag Two Machine Factory '.uniqid());
    $admin = makeGrowbagAdmin($teamId);

    DB::table('pulse_master_growbag_machines')->insert([
        ['team_id' => $teamId, 'device_id' => 71000 + $teamId, 'field_id' => 'd91', 'machine_name' => 'M1', 'bags_per_punch' => 2, 'is_active' => 1],
        ['team_id' => $teamId, 'device_id' => 71000 + $teamId, 'field_id' => 'd92', 'machine_name' => 'M2', 'bags_per_punch' => 4, 'is_active' => 1],
    ]);

    $this->actingAs($admin)
        ->get("/{$teamId}/dashboard/growbags")
        ->assertSuccessful()
        ->assertSee('machineCheckboxes', false)
        ->assertSee('machineChartEmpty', false)
        ->assertSee('id="summaryTableContainer"', false);
});

/**
 * Labour is withdrawn from growbag-only factories, so the salary widget the fibre dashboard
 * dropped has no place here either — and the AJAX payload no longer carries attendance to feed it.
 */
it('no longer renders the salary widget', function () {
    $teamId = makeGrowbagTeam('Growbag No Salary Factory '.uniqid());
    $admin = makeGrowbagAdmin($teamId);

    $this->actingAs($admin)
        ->get("/{$teamId}/dashboard/growbags")
        ->assertSuccessful()
        ->assertDontSee('attendanceWidget', false)
        ->assertDontSee('renderAttendanceChart', false)
        ->assertDontSee('attendance-chart', false);

    $this->actingAs($admin)
        ->getJson("/{$teamId}/dashboard/growbags?start=".now()->toDateString().'&end='.now()->toDateString().'&type=day')
        ->assertOk()
        ->assertJsonMissingPath('data.attendance');
});

/**
 * The dashboards pin their period picker, so a card scrolling under that header lost its title.
 * Each card heading pins itself just below the header instead.
 */
it('pins each chart card heading below the sticky page header', function () {
    $teamId = makeGrowbagTeam('Growbag Sticky Heading Factory '.uniqid());
    $admin = makeGrowbagAdmin($teamId);

    $this->actingAs($admin)
        ->get("/{$teamId}/dashboard/growbags")
        ->assertSuccessful()
        ->assertSee('--pulse-header-bottom', false)
        ->assertSee('pulse-page-header', false);
});

/**
 * Day view sources its figures differently depending on whether the summary row exists: the
 * nightly job de-duplicates readings and corrects counter resets, so its total wins over one
 * recomputed from raw device rows.
 */
function seedGrowbagDay(int $teamId, int $deviceId, string $date, string $anchor, int $rawPunches, ?int $summaryBags): int
{
    DB::table('pulse_settings')->insert([
        'team_id' => $teamId, 'module' => 'shifts', 'key' => 'SHIFT_DAY_ANCHOR',
        'text' => 'Shift day anchor', 'value' => $anchor,
    ]);
    Shift::forgetAnchorCache($teamId);

    $shiftId = DB::table('pulse_shifts')->insertGetId([
        'team_id' => $teamId, 'device_id' => $deviceId, 'name' => 'Shift 1',
        'start_time' => $anchor, 'end_time' => '23:59:59', 'is_active' => 1,
    ]);

    DB::table('pulse_devices')->insert([
        'team_id' => $teamId, 'device_id' => $deviceId, 'module' => 'growbags', 'status' => 'active',
    ]);

    $machineId = DB::table('pulse_master_growbag_machines')->insertGetId([
        'team_id' => $teamId, 'device_id' => $deviceId, 'field_id' => 'd99',
        'machine_name' => 'M1', 'bags_per_punch' => 1, 'is_active' => 1,
    ]);

    $rows = [];
    for ($i = 0; $i < $rawPunches; $i++) {
        $rows[] = [
            'device_id' => $deviceId,
            'field_id' => 'd99',
            'growbag_date' => $date,
            'growbag_datetime' => Carbon\Carbon::parse($date.' '.$anchor)->addMinutes(30 + $i),
            'growbags_counter' => $i + 1,
            'eb_units_counter' => $i + 1,
            'is_duplicate' => 0,
        ];
    }
    DB::table('pulse_device_data_growbags')->insert($rows);

    if ($summaryBags !== null) {
        DB::table('pulse_summary_growbags')->insert([
            'team_id' => $teamId, 'growbag_machine_id' => $machineId, 'machine_name' => 'M1',
            'shift_date' => $date, 'shift_id' => $shiftId, 'shift_name' => 'Shift 1',
            'bags_produced' => $summaryBags, 'eb_units' => 12, 'eb_price' => 120,
        ]);
    }

    return $shiftId;
}

function growbagDayProduction(User $admin, int $teamId, string $date): array
{
    return test()->actingAs($admin)
        ->getJson("/{$teamId}/dashboard/growbags?start={$date}&end={$date}&type=day")
        ->json('data.growbagsProduction');
}

it('takes a day\'s shift figures from the summary table, not the raw device rows', function () {
    $teamId = makeGrowbagTeam('Growbag Settled Day Factory '.uniqid());
    $admin = makeGrowbagAdmin($teamId);

    // Raw rows say 9 punches; the nightly job settled on 7 bags.
    $date = now()->subDays(3)->toDateString();
    $shiftId = seedGrowbagDay($teamId, 80000 + $teamId, $date, '07:00:00', rawPunches: 9, summaryBags: 7);

    $production = growbagDayProduction($admin, $teamId, $date);

    expect($production['shiftSummary'][$shiftId]['growbags'])->toBe(7)
        ->and($production['total_growbags'])->toBe(7)
        ->and($production['shiftSummary'][$shiftId]['eb_units'])->toEqual(12.0)
        ->and($production['total_eb_units'])->toEqual(12.0);
});

it('keeps its live figures when a day has no summary row', function () {
    $teamId = makeGrowbagTeam('Growbag Missing Summary Factory '.uniqid());
    $admin = makeGrowbagAdmin($teamId);

    // The nightly job never ran for this day. Falling through to zero would report no production.
    $date = now()->subDays(4)->toDateString();
    $shiftId = seedGrowbagDay($teamId, 81000 + $teamId, $date, '07:00:00', rawPunches: 9, summaryBags: null);

    $production = growbagDayProduction($admin, $teamId, $date);

    // The shift is counted from the readings themselves, so the tiles, the shift chips and
    // the bars all report the day rather than the tiles reporting nothing.
    expect($production['shiftSummary'][$shiftId]['growbags'])->toBe(9)
        ->and($production['total_growbags'])->toBe(9)
        ->and(collect($production['data'])->sum('day_growbags'))->toBe(9);
});

it('reports eb units per shift over a date range', function () {
    $teamId = makeGrowbagTeam('Growbag Range Factory '.uniqid());
    $admin = makeGrowbagAdmin($teamId);

    $date = now()->subDays(5)->toDateString();
    $shiftId = seedGrowbagDay($teamId, 82000 + $teamId, $date, '07:00:00', rawPunches: 3, summaryBags: 40);

    $production = test()->actingAs($admin)
        ->getJson("/{$teamId}/dashboard/growbags?start={$date}&end={$date}&type=week")
        ->json('data.growbagsProduction');

    expect($production['total_eb_units'])->toEqual(12.0)
        ->and($production['shiftSummary'][$shiftId]['eb_units'])->toEqual(12.0)
        ->and($production['shiftSummary'][$shiftId]['growbags'])->toBe(40);
});

/**
 * The IoT Insights calendar states its unit under the grid, and hands the server the bucket
 * and rendering mode the granularity buttons picked. The density scale that once sat beside
 * the heading went in "iot ui improvements" (4023622c); what decides between density bars
 * and individual punch marks is DENSITY_MIN_BUCKET, on the page itself.
 */
it('renders the growbag IoT insights calendar with its density chrome', function () {
    $teamId = makeGrowbagTeam('Growbag Insights Factory '.uniqid());
    $admin = makeGrowbagAdmin($teamId);

    Shift::insert([
        ['team_id' => $teamId, 'device_id' => 0, 'name' => 'Shift 1', 'start_time' => '07:00:00', 'end_time' => '19:00:00', 'is_active' => 1],
    ]);

    DB::table('pulse_master_growbag_machines')->insert([
        'team_id' => $teamId, 'device_id' => 72000 + $teamId, 'field_id' => 'd93',
        'machine_name' => 'M1', 'bags_per_punch' => 2, 'is_active' => 1,
    ]);

    $this->actingAs($admin)
        ->get("/{$teamId}/iot-growbags")
        ->assertSuccessful()
        ->assertSee('Values are in Bags', false)
        ->assertSee('DENSITY_MIN_BUCKET', false)
        // The granularity buttons re-aggregate server-side now, so the fetch has to carry
        // the bucket the buttons picked.
        ->assertSee('bucket:  bucketSeconds', false)
        ->assertSee('mode:    renderMode', false);
});
