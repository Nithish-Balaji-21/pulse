<?php

use App\Models\Pulse\Shift;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Pulse\Support\TeamFactory;

/**
 * The IoT Insights fibre calendar overlays the shift day still in progress, which the
 * nightly job has not summarised yet. These cover what that overlay must not do: count the
 * same readings twice, or drag the previous night's post-midnight tail onto today.
 */
function iotFibreTeam(string $anchor): int
{
    $teamId = TeamFactory::create('Fibre Insights Factory '.uniqid());

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

function insertBaleReading(int $deviceId, string $datetime, int $counter, ?int $shiftId = null): void
{
    DB::table('pulse_device_data_fibre_bales')->insert([
        'device_id' => $deviceId,
        'bale_date' => substr($datetime, 0, 10),
        'bale_datetime' => $datetime,
        'bales_counter' => $counter,
        'is_duplicate' => 0,
        'eb_units_counter' => $counter,
        'shift_id' => $shiftId,
        'created_at' => $datetime,
        'updated_at' => $datetime,
    ]);
}

function insertBaleSummary(int $teamId, int $deviceId, string $shiftDate, Shift $shift, int $bales): void
{
    DB::table('pulse_summary_fibre_mega_bales')->insert([
        'team_id' => $teamId,
        'device_id' => $deviceId,
        'shift_date' => $shiftDate,
        'shift_id' => $shift->id,
        'shift_name' => $shift->name,
        'bales_produced' => $bales,
        'eb_price' => 0,
    ]);
}

/**
 * The all-day figures drawn on one calendar column, as [shift label => quantity], with
 * a 'Total' entry on days that ran more than one shift.
 *
 * The three pages draw the same card — a titled bar row per shift under the day's
 * headline total — but each prefixes its own classes, so each markup is read here
 * rather than in every test.
 *
 * @return array<string, int>
 */
function chipsOn(array $events, string $date): array
{
    $chips = [];
    $toInt = fn (string $figure): int => (int) str_replace(',', '', $figure);

    foreach ($events as $event) {
        if (! ($event['allDay'] ?? false) || $event['start'] !== $date) {
            continue;
        }

        $card = $event['title'];

        // Fibre bales: one card per day, a titled bar line per shift ("Day: 30 bales"),
        // the day's total in the headline.
        if (str_contains($card, 'fb-card')) {
            preg_match_all("/title='([^':]+): ([\d,]+) bales/", $card, $lines, PREG_SET_ORDER);
            foreach ($lines as [, $label, $quantity]) {
                $chips[$label] = $toInt($quantity);
            }
            if (count($lines) > 1 && preg_match("/fb-big'>([\d,]+)/", $card, $total)) {
                $chips['Total'] = $toInt($total[1]);
            }

            continue;
        }

        // Fibre bale weights: one card per day, a titled bar line per shift
        // ("Day: 3 bales"), the day's total in the headline.
        if (str_contains($card, 'fw-card')) {
            preg_match_all("/title='([^':]+): ([\d,]+) bales/", $card, $lines, PREG_SET_ORDER);
            foreach ($lines as [, $label, $quantity]) {
                $chips[$label] = $toInt($quantity);
            }
            if (count($lines) > 1 && preg_match("/fw-big'>([\d,]+)/", $card, $total)) {
                $chips['Total'] = $toInt($total[1]);
            }

            continue;
        }

        // Growbags: one card per day, a titled bar line per shift ("Day: 40 bags"),
        // the day's total in the headline.
        if (str_contains($card, 'gb-card')) {
            preg_match_all("/title='([^':]+): ([\d,]+) bags/", $card, $lines, PREG_SET_ORDER);
            foreach ($lines as [, $label, $quantity]) {
                $chips[$label] = $toInt($quantity);
            }
            if (count($lines) > 1 && preg_match("/gb-big'>([\d,]+)/", $card, $total)) {
                $chips['Total'] = $toInt($total[1]);
            }

            continue;
        }
    }

    return $chips;
}

function fetchInsightEvents($test, int $teamId, string $page): array
{
    return $test->getJson("/{$teamId}/{$page}/fetch-events?start=2026-08-03&end=2026-08-09")
        ->assertOk()
        ->json();
}

function fetchFibreEvents($test, int $teamId): array
{
    return fetchInsightEvents($test, $teamId, 'iot-fibre-bales');
}

/**
 * The density view's bars, as [bucket start => CSS background], in grid order.
 *
 * @return array<string, string>
 */
function fibreDensityFills($test, int $teamId, int $bucket): array
{
    $events = $test->getJson("/{$teamId}/iot-fibre-bales/fetch-events?start=2026-08-03&end=2026-08-09&mode=density&bucket={$bucket}")
        ->assertOk()
        ->json('events');

    return collect($events)
        ->reject(fn (array $event) => $event['allDay'] ?? false)
        ->mapWithKeys(fn (array $event) => [$event['start'] => $event['extendedProps']['fill']])
        ->all();
}

/**
 * The growbag density view's bars, as [bucket start => [bags, fill]], in grid order.
 *
 * @return array<string, array{bags: int, fill: string}>
 */
function growbagDensityBars($test, int $teamId, int $bucket): array
{
    $events = $test->getJson("/{$teamId}/iot-growbags/fetch-events?start=2026-08-03&end=2026-08-09&mode=density&bucket={$bucket}")
        ->assertOk()
        ->json('events');

    return collect($events)
        ->reject(fn (array $event) => $event['allDay'] ?? false)
        ->mapWithKeys(fn (array $event) => [$event['start'] => [
            'bags' => $event['extendedProps']['bags'],
            'fill' => $event['extendedProps']['fill'],
        ]])
        ->all();
}

function insertGrowbagMachine(int $teamId, int $deviceId, int $bagsPerPunch): int
{
    return (int) DB::table('pulse_master_growbag_machines')->insertGetId([
        'team_id' => $teamId,
        'device_id' => $deviceId,
        'field_id' => 'g1',
        'machine_name' => 'GB1',
        'bags_per_punch' => $bagsPerPunch,
        'is_active' => 1,
    ]);
}

/**
 * The all-day card drawn on one growbag calendar column, or '' when that column has none.
 */
function growbagCardOn(array $events, string $date): string
{
    foreach ($events as $event) {
        if (($event['allDay'] ?? false) && $event['start'] === $date) {
            return $event['title'];
        }
    }

    return '';
}

/**
 * The shift label on each row of a growbag card, in order, with a reserved but empty
 * slot as ''.
 *
 * @return array<int, string>
 */
function growbagShiftSlots(string $card): array
{
    $doc = new DOMDocument;
    libxml_use_internal_errors(true);
    $doc->loadHTML('<meta charset="utf-8"><div>'.$card.'</div>');
    libxml_clear_errors();

    $xpath = new DOMXPath($doc);
    $slots = [];

    foreach ($xpath->query('//span[contains(@class, "gb-sline")]') as $row) {
        $label = $xpath->query('.//span[contains(@class, "gb-lbl")]', $row);
        $slots[] = $label->length ? trim($label->item(0)->textContent) : '';
    }

    return $slots;
}

function insertGrowbagSummary(int $teamId, string $shiftDate, Shift $shift, int $bags, float $ebPrice = 0): void
{
    DB::table('pulse_summary_growbags')->insert([
        'team_id' => $teamId,
        'growbag_machine_id' => 1,
        'machine_name' => 'GB1',
        'shift_date' => $shiftDate,
        'shift_id' => $shift->id,
        'shift_name' => $shift->name,
        'bags_produced' => $bags,
        'eb_price' => $ebPrice,
    ]);
}

function insertGrowbagReading(int $deviceId, string $datetime, int $counter, ?int $shiftId = null): void
{
    DB::table('pulse_device_data_growbags')->insert([
        'device_id' => $deviceId,
        'field_id' => 'g1',
        'growbag_date' => substr($datetime, 0, 10),
        'growbag_datetime' => $datetime,
        'growbags_counter' => $counter,
        'is_duplicate' => 0,
        'shift_id' => $shiftId,
        'created_at' => $datetime,
        'updated_at' => $datetime,
    ]);
}

function insertWeightReading(int $deviceId, string $datetime, float $weight, ?int $shiftId = null): void
{
    DB::table('pulse_device_data_fibre_mega_bales_weight')->insert([
        'device_id' => $deviceId,
        'bale_weight_date' => substr($datetime, 0, 10),
        'bale_weight_datetime' => $datetime,
        'bale_weight' => $weight,
        'shift_id' => $shiftId,
        'created_at' => $datetime,
        'updated_at' => $datetime,
    ]);
}

beforeEach(function () {
    Shift::forgetAnchorCache();

    $this->travelTo('2026-08-08 11:30:00');

    $this->teamId = iotFibreTeam('07:00:00');
    $this->deviceId = 990000 + random_int(1, 9999);

    DB::table('pulse_devices')->insert([
        'team_id' => $this->teamId,
        'device_id' => $this->deviceId,
        'module' => 'fibre',
        'status' => 'active',
    ]);

    $this->user = User::factory()->create([
        'is_superadmin' => 1,
        'current_team_id' => $this->teamId,
    ]);
    $this->actingAs($this->user);
});

it('counts the live shift day once instead of once per attribution path', function () {
    $day = Shift::create(['team_id' => $this->teamId, 'name' => 'Day', 'start_time' => '07:00:00', 'end_time' => '18:00:00', 'is_active' => 1]);
    $night = Shift::create(['team_id' => $this->teamId, 'name' => 'Night', 'start_time' => '18:00:00', 'end_time' => '07:00:00', 'is_active' => 1]);

    // Yesterday, already processed: shift_id stamped and summarised
    insertBaleSummary($this->teamId, $this->deviceId, '2026-08-07', $day, 30);
    insertBaleSummary($this->teamId, $this->deviceId, '2026-08-07', $night, 40);
    insertBaleReading($this->deviceId, '2026-08-07 19:00:00', 100, $night->id);
    insertBaleReading($this->deviceId, '2026-08-08 06:00:00', 139, $night->id);

    // Today so far: the job has not run, so these readings carry no shift_id yet
    insertBaleReading($this->deviceId, '2026-08-08 07:30:00', 140);
    insertBaleReading($this->deviceId, '2026-08-08 11:00:00', 159);

    $response = fetchFibreEvents($this, $this->teamId);

    expect(chipsOn($response['events'], '2026-08-08'))->toBe(['Day' => 20])
        ->and($response['totals'])->not->toHaveKey('Others');
});

it('leaves the post-midnight tail on the shift day it started', function () {
    $day = Shift::create(['team_id' => $this->teamId, 'name' => 'Day', 'start_time' => '07:00:00', 'end_time' => '18:00:00', 'is_active' => 1]);
    $night = Shift::create(['team_id' => $this->teamId, 'name' => 'Night', 'start_time' => '18:00:00', 'end_time' => '07:00:00', 'is_active' => 1]);

    insertBaleSummary($this->teamId, $this->deviceId, '2026-08-07', $day, 30);
    insertBaleSummary($this->teamId, $this->deviceId, '2026-08-07', $night, 40);
    insertBaleReading($this->deviceId, '2026-08-08 02:00:00', 120, $night->id);
    insertBaleReading($this->deviceId, '2026-08-08 06:00:00', 139, $night->id);

    insertBaleReading($this->deviceId, '2026-08-08 07:30:00', 140);
    insertBaleReading($this->deviceId, '2026-08-08 11:00:00', 159);

    $response = fetchFibreEvents($this, $this->teamId);

    // The overnight readings belong to yesterday's night shift, which already counted them
    expect(chipsOn($response['events'], '2026-08-07'))->toBe(['Day' => 30, 'Night' => 40, 'Total' => 70])
        ->and(chipsOn($response['events'], '2026-08-08'))->toBe(['Day' => 20])
        ->and($response['totals']['Night']['total_bales'])->toBe(40);
});

it('treats yesterday as the live day until the anchor passes', function () {
    $this->travelTo('2026-08-08 03:00:00');

    Shift::create(['team_id' => $this->teamId, 'name' => 'Day', 'start_time' => '07:00:00', 'end_time' => '18:00:00', 'is_active' => 1]);
    Shift::create(['team_id' => $this->teamId, 'name' => 'Night', 'start_time' => '18:00:00', 'end_time' => '07:00:00', 'is_active' => 1]);

    insertBaleReading($this->deviceId, '2026-08-07 08:00:00', 1);
    insertBaleReading($this->deviceId, '2026-08-07 17:00:00', 10);
    insertBaleReading($this->deviceId, '2026-08-07 19:00:00', 11);
    insertBaleReading($this->deviceId, '2026-08-08 02:00:00', 25);

    $response = fetchFibreEvents($this, $this->teamId);

    expect($response['liveDay'])->toBe('2026-08-07')
        ->and(chipsOn($response['events'], '2026-08-07'))->toBe(['Day' => 10, 'Night' => 15, 'Total' => 25])
        ->and(chipsOn($response['events'], '2026-08-08'))->toBe([]);
});

it('counts the growbags live shift day once, on the shift day it belongs to', function () {
    $day = Shift::create(['team_id' => $this->teamId, 'name' => 'Day', 'start_time' => '07:00:00', 'end_time' => '18:00:00', 'is_active' => 1]);
    $night = Shift::create(['team_id' => $this->teamId, 'name' => 'Night', 'start_time' => '18:00:00', 'end_time' => '07:00:00', 'is_active' => 1]);

    foreach ([[$day, 30], [$night, 40]] as [$shift, $bags]) {
        DB::table('pulse_summary_growbags')->insert([
            'team_id' => $this->teamId,
            'growbag_machine_id' => 1,
            'machine_name' => 'GB1',
            'shift_date' => '2026-08-07',
            'shift_id' => $shift->id,
            'shift_name' => $shift->name,
            'bags_produced' => $bags,
            'eb_price' => 0,
        ]);
    }

    // Last night's tail, already stamped and summarised under 2026-08-07
    insertGrowbagReading($this->deviceId, '2026-08-08 02:00:00', 100, $night->id);
    insertGrowbagReading($this->deviceId, '2026-08-08 06:00:00', 139, $night->id);

    // Today so far, not yet processed
    insertGrowbagReading($this->deviceId, '2026-08-08 07:30:00', 140);
    insertGrowbagReading($this->deviceId, '2026-08-08 11:00:00', 159);

    $response = fetchInsightEvents($this, $this->teamId, 'iot-growbags');

    expect(chipsOn($response['events'], '2026-08-08'))->toBe(['Day' => 20])
        ->and(chipsOn($response['events'], '2026-08-07'))->toBe(['Day' => 30, 'Night' => 40, 'Total' => 70]);
});

it('counts the bale-weight live shift day once, on the shift day it belongs to', function () {
    $day = Shift::create(['team_id' => $this->teamId, 'name' => 'Day', 'start_time' => '07:00:00', 'end_time' => '18:00:00', 'is_active' => 1]);
    $night = Shift::create(['team_id' => $this->teamId, 'name' => 'Night', 'start_time' => '18:00:00', 'end_time' => '07:00:00', 'is_active' => 1]);

    foreach ([[$day, 3], [$night, 4]] as [$shift, $weighed]) {
        DB::table('pulse_summary_fibre_mega_bales')->insert([
            'team_id' => $this->teamId,
            'device_id' => $this->deviceId,
            'shift_date' => '2026-08-07',
            'shift_id' => $shift->id,
            'shift_name' => $shift->name,
            'bales_weighed' => $weighed,
            'weight' => $weighed * 120,
        ]);
    }

    insertWeightReading($this->deviceId, '2026-08-08 02:00:00', 120, $night->id);
    insertWeightReading($this->deviceId, '2026-08-08 05:00:00', 120, $night->id);

    insertWeightReading($this->deviceId, '2026-08-08 08:00:00', 130);
    insertWeightReading($this->deviceId, '2026-08-08 10:00:00', 130);

    $response = fetchInsightEvents($this, $this->teamId, 'iot-fibre-weight');

    expect(chipsOn($response['events'], '2026-08-08'))->toBe(['Day' => 2])
        ->and($response['totals']['Day']['total_bales_weighed'])->toBe(5)
        ->and($response['totals'])->not->toHaveKey('Others');
});

it('gives live production in a shift coverage gap to the nearer shift', function () {
    $this->travelTo('2026-08-08 21:00:00');

    Shift::create(['team_id' => $this->teamId, 'name' => 'Day', 'start_time' => '07:00:00', 'end_time' => '18:00:00', 'is_active' => 1]);
    Shift::create(['team_id' => $this->teamId, 'name' => 'Night', 'start_time' => '20:00:00', 'end_time' => '07:00:00', 'is_active' => 1]);

    insertBaleReading($this->deviceId, '2026-08-08 07:30:00', 1);
    insertBaleReading($this->deviceId, '2026-08-08 17:00:00', 5);
    // 18:00–20:00 is uncovered; 19:00 is its midpoint, and a tie goes to the later shift
    insertBaleReading($this->deviceId, '2026-08-08 19:00:00', 6);
    insertBaleReading($this->deviceId, '2026-08-08 20:30:00', 7);
    insertBaleReading($this->deviceId, '2026-08-08 20:45:00', 8);

    $response = fetchFibreEvents($this, $this->teamId);

    expect(chipsOn($response['events'], '2026-08-08'))->toBe(['Day' => 5, 'Night' => 3, 'Total' => 8])
        ->and($response['totals'])->not->toHaveKey('Others');
});

/**
 * The density bars (30m and coarser) used to take a single colour for the whole grid, which
 * happened to be shift 2's, so every bar read as night production.
 */
it('colours a density bar with the shift its bales were produced in', function () {
    Shift::create(['team_id' => $this->teamId, 'name' => 'Day', 'start_time' => '07:00:00', 'end_time' => '18:00:00', 'is_active' => 1]);
    Shift::create(['team_id' => $this->teamId, 'name' => 'Night', 'start_time' => '18:00:00', 'end_time' => '07:00:00', 'is_active' => 1]);

    insertBaleReading($this->deviceId, '2026-08-07 09:10:00', 1);
    insertBaleReading($this->deviceId, '2026-08-07 09:20:00', 2);
    insertBaleReading($this->deviceId, '2026-08-07 19:10:00', 3);

    expect(fibreDensityFills($this, $this->teamId, 1800))->toBe([
        '2026-08-07 09:00:00' => config('colors.shift.1'),
        '2026-08-07 19:00:00' => config('colors.shift.2'),
    ]);
});

it('bands a density bar the shift changeover runs through', function () {
    Shift::create(['team_id' => $this->teamId, 'name' => 'Day', 'start_time' => '07:00:00', 'end_time' => '18:00:00', 'is_active' => 1]);
    Shift::create(['team_id' => $this->teamId, 'name' => 'Night', 'start_time' => '18:00:00', 'end_time' => '07:00:00', 'is_active' => 1]);

    // At 2h the 07:00 anchor puts one bucket across 17:00–19:00, so the 18:00 changeover
    // falls inside it: three of the four bales are the day shift's, one the night's.
    insertBaleReading($this->deviceId, '2026-08-07 17:10:00', 1);
    insertBaleReading($this->deviceId, '2026-08-07 17:20:00', 2);
    insertBaleReading($this->deviceId, '2026-08-07 17:30:00', 3);
    insertBaleReading($this->deviceId, '2026-08-07 18:30:00', 4);

    expect(fibreDensityFills($this, $this->teamId, 7200)['2026-08-07 17:00:00'])
        ->toBe('linear-gradient(to right, '.config('colors.shift.1').' 0.00% 75.00%, '.config('colors.shift.2').' 75.00% 100.00%)');
});

it('splits a shift coverage gap at its midpoint', function () {
    $this->travelTo('2026-08-08 21:00:00');

    Shift::create(['team_id' => $this->teamId, 'name' => 'Day', 'start_time' => '07:00:00', 'end_time' => '18:00:00', 'is_active' => 1]);
    Shift::create(['team_id' => $this->teamId, 'name' => 'Night', 'start_time' => '20:00:00', 'end_time' => '07:00:00', 'is_active' => 1]);

    insertBaleReading($this->deviceId, '2026-08-08 08:00:00', 1);
    insertBaleReading($this->deviceId, '2026-08-08 18:30:00', 2);  // gap, nearer Day
    insertBaleReading($this->deviceId, '2026-08-08 19:30:00', 3);  // gap, nearer Night
    insertBaleReading($this->deviceId, '2026-08-08 20:30:00', 4);

    $response = fetchFibreEvents($this, $this->teamId);

    expect(chipsOn($response['events'], '2026-08-08'))->toBe(['Day' => 2, 'Night' => 2, 'Total' => 4]);
});

/**
 * The growbag calendar counts punches, but every figure it prints is in bags: the summary
 * table, the day chips and the density bars all have to agree, and a punch is worth
 * `bags_per_punch` bags on the machine that made it.
 */
it('scales growbag density bars from punches to bags, coloured by shift', function () {
    Shift::create(['team_id' => $this->teamId, 'name' => 'Day', 'start_time' => '07:00:00', 'end_time' => '18:00:00', 'is_active' => 1]);
    Shift::create(['team_id' => $this->teamId, 'name' => 'Night', 'start_time' => '18:00:00', 'end_time' => '07:00:00', 'is_active' => 1]);
    insertGrowbagMachine($this->teamId, $this->deviceId, 2);

    insertGrowbagReading($this->deviceId, '2026-08-07 09:10:00', 1);
    insertGrowbagReading($this->deviceId, '2026-08-07 09:20:00', 2);
    insertGrowbagReading($this->deviceId, '2026-08-07 19:10:00', 3);

    expect(growbagDensityBars($this, $this->teamId, 1800))->toBe([
        '2026-08-07 09:00:00' => ['bags' => 4, 'fill' => config('colors.shift.1')],
        '2026-08-07 19:00:00' => ['bags' => 2, 'fill' => config('colors.shift.2')],
    ]);
});

it('bands a growbag density bar the shift changeover runs through', function () {
    Shift::create(['team_id' => $this->teamId, 'name' => 'Day', 'start_time' => '07:00:00', 'end_time' => '18:00:00', 'is_active' => 1]);
    Shift::create(['team_id' => $this->teamId, 'name' => 'Night', 'start_time' => '18:00:00', 'end_time' => '07:00:00', 'is_active' => 1]);
    insertGrowbagMachine($this->teamId, $this->deviceId, 1);

    // At 2h the 07:00 anchor puts one bucket across 17:00–19:00, so the 18:00 changeover
    // falls inside it: three of the four punches are the day shift's, one the night's.
    insertGrowbagReading($this->deviceId, '2026-08-07 17:10:00', 1);
    insertGrowbagReading($this->deviceId, '2026-08-07 17:20:00', 2);
    insertGrowbagReading($this->deviceId, '2026-08-07 17:30:00', 3);
    insertGrowbagReading($this->deviceId, '2026-08-07 18:30:00', 4);

    expect(growbagDensityBars($this, $this->teamId, 7200)['2026-08-07 17:00:00']['fill'])
        ->toBe('linear-gradient(to right, '.config('colors.shift.1').' 0.00% 75.00%, '.config('colors.shift.2').' 75.00% 100.00%)');
});

it('counts the growbags live day in bags, not punches', function () {
    Shift::create(['team_id' => $this->teamId, 'name' => 'Day', 'start_time' => '07:00:00', 'end_time' => '18:00:00', 'is_active' => 1]);
    insertGrowbagMachine($this->teamId, $this->deviceId, 2);

    // 20 punches so far today, on a machine producing 2 bags a punch.
    insertGrowbagReading($this->deviceId, '2026-08-08 07:30:00', 140);
    insertGrowbagReading($this->deviceId, '2026-08-08 11:00:00', 159);

    $response = fetchInsightEvents($this, $this->teamId, 'iot-growbags');

    expect(chipsOn($response['events'], '2026-08-08'))->toBe(['Day' => 40])
        ->and($response['totals']['Day']['total_bags'])->toBe(40);
});

/**
 * Production in a gap between two shifts used to be pooled under an 'Others' heading, which
 * read on the strip as a shift nobody works.
 */
it('gives live growbag production in a shift coverage gap to the nearer shift', function () {
    $this->travelTo('2026-08-08 21:00:00');

    Shift::create(['team_id' => $this->teamId, 'name' => 'Day', 'start_time' => '07:00:00', 'end_time' => '18:00:00', 'is_active' => 1]);
    Shift::create(['team_id' => $this->teamId, 'name' => 'Night', 'start_time' => '20:00:00', 'end_time' => '07:00:00', 'is_active' => 1]);
    insertGrowbagMachine($this->teamId, $this->deviceId, 1);

    insertGrowbagReading($this->deviceId, '2026-08-08 08:00:00', 1);
    insertGrowbagReading($this->deviceId, '2026-08-08 18:30:00', 2);  // gap, nearer Day
    insertGrowbagReading($this->deviceId, '2026-08-08 19:30:00', 3);  // gap, nearer Night
    insertGrowbagReading($this->deviceId, '2026-08-08 20:30:00', 4);

    $response = fetchInsightEvents($this, $this->teamId, 'iot-growbags');

    expect(chipsOn($response['events'], '2026-08-08'))->toBe(['Day' => 2, 'Night' => 2, 'Total' => 4])
        ->and($response['totals'])->not->toHaveKey('Others');
});

/**
 * The growbag calendar's all-day band draws the fibre card: one panel per column, the
 * day's total over a bar row per shift. A week has to read across as seven columns, so
 * a day that produced nothing still gets a card and a shift that sat one day out still
 * keeps its line in that day's card.
 */
it('draws a growbag card for every day up to the live day', function () {
    $day = Shift::create(['team_id' => $this->teamId, 'name' => 'Shift 1', 'start_time' => '07:00:00', 'end_time' => '18:00:00', 'is_active' => 1]);
    $night = Shift::create(['team_id' => $this->teamId, 'name' => 'Shift 2', 'start_time' => '18:00:00', 'end_time' => '07:00:00', 'is_active' => 1]);
    insertGrowbagMachine($this->teamId, $this->deviceId, 1);

    insertGrowbagSummary($this->teamId, '2026-08-05', $day, 30);
    insertGrowbagSummary($this->teamId, '2026-08-05', $night, 40);
    // 08-06 ran the night shift only; the day shift keeps its line all the same.
    insertGrowbagSummary($this->teamId, '2026-08-06', $night, 20);

    $events = fetchInsightEvents($this, $this->teamId, 'iot-growbags')['events'];

    expect(growbagShiftSlots(growbagCardOn($events, '2026-08-05')))->toBe(['S1', 'S2'])
        ->and(growbagShiftSlots(growbagCardOn($events, '2026-08-06')))->toBe(['', 'S2'])
        // 08-04 produced nothing, and says so rather than leaving a gap in the week.
        ->and(growbagCardOn($events, '2026-08-04'))->toContain('no production')
        ->and(growbagShiftSlots(growbagCardOn($events, '2026-08-04')))->toBe(['', ''])
        // The live day is 08-08; 08-09 has not happened, so it gets no card at all.
        ->and(growbagCardOn($events, '2026-08-08'))->not->toBe('')
        ->and(growbagCardOn($events, '2026-08-09'))->toBe('');
});

/**
 * The energy line is the reason the card exists rather than a chip: a day's cost, and
 * what a bag of it cost, sit on the same panel as the count they come from.
 */
it('prices a growbag day per bag when the nightly job has posted a cost', function () {
    $day = Shift::create(['team_id' => $this->teamId, 'name' => 'Shift 1', 'start_time' => '07:00:00', 'end_time' => '18:00:00', 'is_active' => 1]);
    insertGrowbagMachine($this->teamId, $this->deviceId, 1);

    insertGrowbagSummary($this->teamId, '2026-08-05', $day, 40, 30);

    $card = growbagCardOn(fetchInsightEvents($this, $this->teamId, 'iot-growbags')['events'], '2026-08-05');

    expect($card)->toContain("gb-big'>40")
        ->and($card)->toContain('₹0.75 / bag');
});

/**
 * The live day carries no eb_price yet — the nightly job is what fills it — so the card
 * prints its count without a ₹0 that is not a reading.
 */
it('leaves the cost off a growbag day the nightly job has not priced', function () {
    Shift::create(['team_id' => $this->teamId, 'name' => 'Shift 1', 'start_time' => '07:00:00', 'end_time' => '18:00:00', 'is_active' => 1]);
    insertGrowbagMachine($this->teamId, $this->deviceId, 2);

    insertGrowbagReading($this->deviceId, '2026-08-08 07:30:00', 140);
    insertGrowbagReading($this->deviceId, '2026-08-08 11:00:00', 159);

    $card = growbagCardOn(fetchInsightEvents($this, $this->teamId, 'iot-growbags')['events'], '2026-08-08');

    expect($card)->toContain("gb-big'>40")
        ->and($card)->not->toContain('gb-total-cost')
        ->and($card)->not->toContain('/ bag');
});
