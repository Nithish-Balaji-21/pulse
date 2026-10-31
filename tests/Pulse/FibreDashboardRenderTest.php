<?php

use App\Models\Pulse\Shift;
use App\Models\Team;
use App\Models\User;
use App\Support\Pulse\TeamRolePermissions;
use Illuminate\Support\Facades\DB;
use Tests\Pulse\Support\TeamFactory;

/**
 * The fibre dashboard is a single ~900-line Blade file whose per-shift chips are rendered from
 * the shift list. These are smoke tests: they assert the page renders, that the chips adapt to
 * the shift count, and that a finished day's shift figures come from the nightly summary rather
 * than being recomputed from raw device rows.
 */
function makeFibreTeam(string $name): int
{
    return TeamFactory::create($name, false, [
        'modules' => [
            'pith' => ['is_active' => false, 'submodules' => ['blocks' => false, 'bales' => false]],
            'fibre' => ['is_active' => true, 'submodules' => ['weight' => true]],
            'growbags' => ['is_active' => false],
            'husks_stock' => ['is_active' => false],
            'attendance' => ['is_active' => true],
        ],
    ]);
}

function makeFibreAdmin(int $teamId): User
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

it('renders the fibre dashboard with its summary tiles and chart cards', function () {
    $teamId = makeFibreTeam('Fibre Dashboard Factory '.uniqid());
    $admin = makeFibreAdmin($teamId);

    Shift::insert([
        ['team_id' => $teamId, 'device_id' => 0, 'name' => 'Shift 1', 'start_time' => '07:00:00', 'end_time' => '18:00:00', 'is_active' => 1],
        ['team_id' => $teamId, 'device_id' => 0, 'name' => 'Shift 2', 'start_time' => '18:00:00', 'end_time' => '07:00:00', 'is_active' => 1],
    ]);

    $response = $this->actingAs($admin)
        ->get("/{$teamId}/dashboard/fibre")
        ->assertSuccessful();

    // Every tile the AJAX response populates must exist in the first paint.
    foreach (['kpiBalesProduced', 'kpiBalesWeighed', 'kpiTotalWeight', 'kpiAvgWeight', 'kpiEbCost'] as $tile) {
        $response->assertSee($tile, false);
    }

    $response->assertDontSee('kpiCostPerTonne', false);

    // Each chart card owns a mount, a heading and an empty state.
    foreach (['balesChart', 'weightChart', 'ebChart'] as $chart) {
        $response->assertSee($chart, false);
        $response->assertSee($chart.'Title', false);
        $response->assertSee($chart.'Empty', false);
    }

    $response->assertSee('loading-overlay', false);
    $response->assertSee('dashboard-error', false);

    // Every tile carries a chip per shift, so the breakdown the old Shift Summary widget
    // showed now sits inside the tile it belongs to.
    foreach (['bales', 'weighed', 'weight', 'avgWeight', 'eb'] as $metric) {
        expect(substr_count($response->getContent(), 'data-shift-metric="'.$metric.'"'))->toBe(2);
    }
    $response->assertSee('Shift 1', false);
    $response->assertSee('Shift 2', false);

    // Produced is the red bale and weighed the orange one, matching their chart series. Each
    // is its own asset — fibre-weighed-icon rather than a recolour of the older fibre-icon,
    // which nothing draws any more.
    $response->assertSeeInOrder(['fibre-custom-icon.png', 'kpiBalesProduced'], false)
        ->assertSeeInOrder(['fibre-weighed-icon.png', 'kpiBalesWeighed'], false);
});

it('drops the shift chips for a single-shift factory, where they would only repeat the total', function () {
    $teamId = makeFibreTeam('Fibre Single Shift Factory '.uniqid());
    $admin = makeFibreAdmin($teamId);

    Shift::insert([
        ['team_id' => $teamId, 'device_id' => 0, 'name' => 'General', 'start_time' => '07:00:00', 'end_time' => '19:00:00', 'is_active' => 1],
    ]);

    $response = $this->actingAs($admin)
        ->get("/{$teamId}/dashboard/fibre")
        ->assertSuccessful()
        ->assertSee('kpiBalesProduced', false);

    // No rendered chips — the only `data-shift-metric` left is the selector inside the script.
    foreach (['bales', 'weighed', 'weight', 'avgWeight', 'eb'] as $metric) {
        $response->assertDontSee('data-shift-metric="'.$metric.'"', false);
    }
});

it('no longer renders the shift summary widget', function () {
    $teamId = makeFibreTeam('Fibre No Shift Widget Factory '.uniqid());
    $admin = makeFibreAdmin($teamId);

    Shift::insert([
        ['team_id' => $teamId, 'device_id' => 0, 'name' => 'Shift 1', 'start_time' => '07:00:00', 'end_time' => '18:00:00', 'is_active' => 1],
        ['team_id' => $teamId, 'device_id' => 0, 'name' => 'Shift 2', 'start_time' => '18:00:00', 'end_time' => '07:00:00', 'is_active' => 1],
    ]);

    $this->actingAs($admin)
        ->get("/{$teamId}/dashboard/fibre")
        ->assertSuccessful()
        ->assertDontSee('Shift Summary', false)
        ->assertDontSee('>Total<', false);
});

it('no longer renders the salary widget', function () {
    $teamId = makeFibreTeam('Fibre No Salary Factory '.uniqid());
    $admin = makeFibreAdmin($teamId);

    $this->actingAs($admin)
        ->get("/{$teamId}/dashboard/fibre")
        ->assertSuccessful()
        ->assertDontSee('attendanceWidget', false)
        ->assertDontSee('renderAttendanceChart', false)
        ->assertDontSee('attendance-chart', false);
});

it('no longer references the removed fibre-dashboard stylesheet', function () {
    $teamId = makeFibreTeam('Fibre Stylesheet Factory '.uniqid());
    $admin = makeFibreAdmin($teamId);

    $this->actingAs($admin)
        ->get("/{$teamId}/dashboard/fibre")
        ->assertSuccessful()
        ->assertDontSee('fibre-dashboard.css', false)
        ->assertDontSee('--shift1-color', false);
});

/**
 * Day view sources shift figures differently depending on whether the shift day is over: a
 * finished day reads the nightly summary, which the processing job wrote after de-duplicating
 * readings and correcting counter resets; the day still running has no summary row yet and has
 * to be counted live from raw device data.
 */
/**
 * A single-shift fibre day. The shift has to start on the team's shift-day anchor, otherwise it
 * straddles the anchor and Shift::containsMoment() never matches — which is a property of the
 * anchor arithmetic, not of anything under test here.
 */
function seedFibreDay(int $teamId, int $deviceId, string $date, string $anchor, int $rawBales, ?int $summaryBales): int
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
        'team_id' => $teamId, 'device_id' => $deviceId, 'module' => 'fibre', 'status' => 'active',
    ]);

    // Raw readings just after the anchor, one per bale, so they land inside the shift window.
    $rows = [];
    for ($i = 0; $i < $rawBales; $i++) {
        $rows[] = [
            'device_id' => $deviceId,
            'bale_date' => $date,
            'bale_datetime' => Carbon\Carbon::parse($date.' '.$anchor)->addMinutes(30 + $i),
            'bales_counter' => $i + 1,
            'eb_units_counter' => $i + 1,
            'shift_id' => $shiftId,
        ];
    }
    DB::table('pulse_device_data_fibre_bales')->insert($rows);

    if ($summaryBales !== null) {
        DB::table('pulse_summary_fibre_mega_bales')->insert([
            'team_id' => $teamId, 'device_id' => $deviceId, 'shift_date' => $date,
            'shift_name' => 'Shift 1', 'shift_id' => $shiftId,
            'bales_produced' => $summaryBales, 'bales_weighed' => $summaryBales,
            'weight' => $summaryBales * 130, 'eb_units' => 0, 'eb_price' => 0,
        ]);
    }

    return $shiftId;
}

function fibreDayShiftTotals(User $admin, int $teamId, string $date): array
{
    $response = test()->actingAs($admin)
        ->getJson("/{$teamId}/dashboard/fibre?start={$date}&end={$date}&type=day");

    return $response->json('data.fibreProduction');
}

it('takes a finished day\'s shift figures from the summary table, not the raw device rows', function () {
    $teamId = makeFibreTeam('Fibre Settled Day Factory '.uniqid());
    $admin = makeFibreAdmin($teamId);

    // Raw rows say 9 bales; the nightly job settled on 7 after removing duplicates.
    $date = now()->subDays(3)->toDateString();
    $shiftId = seedFibreDay($teamId, 90000 + $teamId, $date, '07:00:00', rawBales: 9, summaryBales: 7);

    $production = fibreDayShiftTotals($admin, $teamId, $date);

    expect($production['shiftSummary'][$shiftId]['bales_produced'])->toBe(7)
        ->and($production['total_bales_produced'])->toBe(7);

    // The hourly bars still come from raw data — the summary has no hourly grain.
    expect(collect($production['data'])->sum('bales_produced'))->toBe(9);
});

it('counts the in-progress day live, since it has no summary row yet', function () {
    $teamId = makeFibreTeam('Fibre Live Day Factory '.uniqid());
    $admin = makeFibreAdmin($teamId);

    // Anchored at midnight so the window is today whatever time the suite runs.
    $today = now()->toDateString();
    $shiftId = seedFibreDay($teamId, 91000 + $teamId, $today, '00:00:00', rawBales: 9, summaryBales: null);

    $production = fibreDayShiftTotals($admin, $teamId, $today);

    expect($production['shiftSummary'][$shiftId]['bales_produced'])->toBe(9);
});

it('leaves the in-progress day live even once a summary row exists', function () {
    $teamId = makeFibreTeam('Fibre Live Day Override Factory '.uniqid());
    $admin = makeFibreAdmin($teamId);

    // A stale or partial summary row must not replace the live count of a day still running.
    $today = now()->toDateString();
    $shiftId = seedFibreDay($teamId, 93000 + $teamId, $today, '00:00:00', rawBales: 9, summaryBales: 2);

    $production = fibreDayShiftTotals($admin, $teamId, $today);

    expect($production['shiftSummary'][$shiftId]['bales_produced'])->toBe(9);
});

it('keeps its live figures when a finished day has no summary row', function () {
    $teamId = makeFibreTeam('Fibre Missing Summary Factory '.uniqid());
    $admin = makeFibreAdmin($teamId);

    // The nightly job never ran for this day. Falling through to zero would report no production.
    $date = now()->subDays(4)->toDateString();
    $shiftId = seedFibreDay($teamId, 92000 + $teamId, $date, '07:00:00', rawBales: 9, summaryBales: null);

    $production = fibreDayShiftTotals($admin, $teamId, $date);

    expect($production['shiftSummary'][$shiftId]['bales_produced'])->toBe(9);
});
