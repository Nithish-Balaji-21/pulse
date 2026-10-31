<?php

use App\Models\Pulse\Shift;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Pulse\Support\TeamFactory;

/**
 * The IoT Insights pith calendar draws one card per day in its all-day row. These cover
 * what that card must not get wrong: dropping a shift nobody has keyed in yet from the
 * day total, and letting a day's card change height with its shift count so the machine
 * tier drifts out of line with the other six columns.
 *
 * cft_expected is not a target — ProcessDeviceDataForPithJob derives it from the EB meter
 * (eb_units / EB_UNITS_PER_LOAD), so it is a second measurement of the same quantity
 * cft_produced records by hand. Roughly half of all summary rows carry the estimate with
 * no actual beside it, which is why the fallback below is the normal path, not an edge case.
 */
function pithInsightsTeam(): int
{
    $teamId = TeamFactory::create('Pith Insights Factory '.uniqid());

    DB::table('pulse_settings')->insert([
        'team_id' => $teamId,
        'module' => 'shifts',
        'key' => Shift::ANCHOR_SETTING_KEY,
        'text' => 'Shift day anchor (start of the 24-hour production day)',
        'value' => '07:00:00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $teamId;
}

function insertPithSummary(
    int $teamId,
    int $deviceId,
    string $shiftDate,
    Shift $shift,
    ?float $produced,
    float $expected,
): void {
    DB::table('pulse_summary_pith_productions')->insert([
        'team_id' => $teamId,
        'device_id' => $deviceId,
        'shift_date' => $shiftDate,
        'shift_id' => $shift->id,
        'shift_name' => $shift->name,
        'start_time' => $shiftDate.' 07:00:00',
        'end_time' => $shiftDate.' 18:00:00',
        'cft_produced' => $produced,
        'cft_expected' => $expected,
        'eb_units' => $expected / 3.1447,
        'eb_price' => ($expected / 3.1447) * 9.4,
    ]);
}

function insertPowerState(int $deviceId, string $shiftDate, string $state, int $seconds): void
{
    DB::table('pulse_summary_power_states')->insert([
        'device_id' => $deviceId,
        'shift_date' => $shiftDate,
        'date' => $shiftDate,
        'start_time' => $shiftDate.' 07:00:00',
        'end_time' => $shiftDate.' 08:00:00',
        'state' => $state,
        'duration' => $seconds,
    ]);
}

/**
 * The rendered day card for one calendar column.
 */
function pithCardOn(array $events, string $date): string
{
    foreach ($events as $event) {
        if (($event['allDay'] ?? false) && $event['start'] === $date) {
            return $event['title'];
        }
    }

    return '';
}

/**
 * The headline figure and the chip beside it, as [total, chip].
 *
 * @return array{0: int, 1: string}
 */
function pithHeadline(string $card): array
{
    preg_match("/class='pd-big[^']*'>([^<]*)</", $card, $total);
    preg_match("/class='pd-chip[^']*'>([^<]*)</", $card, $chip);

    return [
        (int) str_replace(',', '', trim($total[1] ?? '0')),
        trim($chip[1] ?? ''),
    ];
}

/**
 * The shift label on each row of a card, in order, with an empty slot as ''.
 *
 * @return array<int, string>
 */
function pithShiftSlots(string $card): array
{
    $doc = new DOMDocument;
    libxml_use_internal_errors(true);
    $doc->loadHTML('<meta charset="utf-8"><div>'.$card.'</div>');
    libxml_clear_errors();

    $xpath = new DOMXPath($doc);
    $slots = [];

    foreach ($xpath->query('//span[contains(@class, "pd-sline")]') as $row) {
        $label = $xpath->query('.//span[contains(@class, "pd-lbl")]', $row);
        $slots[] = $label->length ? trim($label->item(0)->textContent) : '';
    }

    return $slots;
}

function fetchPithEvents($test, int $teamId): array
{
    return $test->getJson("/{$teamId}/iot-pith-loads/fetch-events?start=2026-08-03&end=2026-08-09")
        ->assertOk()
        ->json('events');
}

beforeEach(function () {
    Shift::forgetAnchorCache();

    $this->travelTo('2026-08-10 11:30:00');

    $this->teamId = pithInsightsTeam();
    $this->deviceId = 980000 + random_int(1, 9999);

    DB::table('pulse_devices')->insert([
        'team_id' => $this->teamId,
        'device_id' => $this->deviceId,
        'module' => 'pith',
        'status' => 'active',
    ]);

    $this->day = Shift::create([
        'team_id' => $this->teamId, 'name' => 'Shift 1',
        'start_time' => '07:00:00', 'end_time' => '18:00:00', 'is_active' => 1,
    ]);
    $this->night = Shift::create([
        'team_id' => $this->teamId, 'name' => 'Shift 2',
        'start_time' => '18:00:00', 'end_time' => '07:00:00', 'is_active' => 1,
    ]);

    $this->user = User::factory()->create([
        'is_superadmin' => 1,
        'current_team_id' => $this->teamId,
    ]);
    $this->actingAs($this->user);
});

it('falls back to the metered estimate for a shift nobody has entered', function () {
    insertPithSummary($this->teamId, $this->deviceId, '2026-08-04', $this->day, 900, 930);
    insertPithSummary($this->teamId, $this->deviceId, '2026-08-04', $this->night, null, 1710);

    [$total, $chip] = pithHeadline(pithCardOn(fetchPithEvents($this, $this->teamId), '2026-08-04'));

    // 900 entered + 1,710 estimated. Summing only the actuals would report 900 and
    // read as a bad day rather than an incomplete one.
    expect($total)->toBe(2610)
        ->and($chip)->toBe('1 of 2 actual');
});

it('treats a zero actual the same as a missing one', function () {
    insertPithSummary($this->teamId, $this->deviceId, '2026-08-04', $this->day, 0, 1170);
    insertPithSummary($this->teamId, $this->deviceId, '2026-08-04', $this->night, 2346, 1950);

    [$total, $chip] = pithHeadline(pithCardOn(fetchPithEvents($this, $this->teamId), '2026-08-04'));

    expect($total)->toBe(3516)
        ->and($chip)->toBe('1 of 2 actual');
});

it('annotates the headline only when it is not purely what staff entered', function () {
    insertPithSummary($this->teamId, $this->deviceId, '2026-08-04', $this->day, 300, 415);
    insertPithSummary($this->teamId, $this->deviceId, '2026-08-04', $this->night, 600, 534);

    $card = pithCardOn(fetchPithEvents($this, $this->teamId), '2026-08-04');
    [$total, $chip] = pithHeadline($card);

    // Every shift entered, so the total is the plain sum and needs no chip. The
    // divergence from the 949 estimate is drawn by the bands, not written out.
    // strip_tags leaves only the text the operator actually reads — the bar widths
    // and the hover title still carry percentages, and should.
    expect($total)->toBe(900)
        ->and($chip)->toBe('')
        ->and(strip_tags($card))->not->toContain('%');
});

it('totals the estimates when no shift has been entered at all', function () {
    insertPithSummary($this->teamId, $this->deviceId, '2026-08-04', $this->day, null, 415);
    insertPithSummary($this->teamId, $this->deviceId, '2026-08-04', $this->night, null, 534);

    [$total, $chip] = pithHeadline(pithCardOn(fetchPithEvents($this, $this->teamId), '2026-08-04'));

    expect($total)->toBe(949)
        ->and($chip)->toBe('estimate');
});

it('reserves the same number of shift rows on every day of the week', function () {
    // Two shifts on Tuesday, one on Wednesday, none on Thursday.
    insertPithSummary($this->teamId, $this->deviceId, '2026-08-04', $this->day, 300, 415);
    insertPithSummary($this->teamId, $this->deviceId, '2026-08-04', $this->night, 600, 534);
    insertPithSummary($this->teamId, $this->deviceId, '2026-08-05', $this->day, 300, 226);
    insertPowerState($this->deviceId, '2026-08-06', 'off', 86400);

    $events = fetchPithEvents($this, $this->teamId);

    $rowsOn = fn (string $date) => substr_count(pithCardOn($events, $date), "class='pd-sline");

    // A day with fewer shifts pads with empty rows, so the machine tier underneath
    // starts at the same height in all seven columns instead of drifting up and down.
    expect($rowsOn('2026-08-04'))->toBe(2)
        ->and($rowsOn('2026-08-05'))->toBe(2)
        ->and($rowsOn('2026-08-06'))->toBe(2);
});

it('keeps a shift on its own line on days it did not run', function () {
    // Tuesday runs both shifts; Wednesday runs only Shift 2.
    insertPithSummary($this->teamId, $this->deviceId, '2026-08-04', $this->day, 300, 415);
    insertPithSummary($this->teamId, $this->deviceId, '2026-08-04', $this->night, 600, 534);
    insertPithSummary($this->teamId, $this->deviceId, '2026-08-05', $this->night, 1800, 2651);

    $events = fetchPithEvents($this, $this->teamId);

    // Shift 2 must not slide up into Shift 1's empty row, or the same shift would
    // sit on a different line in each column.
    expect(pithShiftSlots(pithCardOn($events, '2026-08-04')))->toBe(['S1', 'S2'])
        ->and(pithShiftSlots(pithCardOn($events, '2026-08-05')))->toBe(['', 'S2']);
});

it('ships both machine rails so switching to All States never refetches', function () {
    insertPithSummary($this->teamId, $this->deviceId, '2026-08-04', $this->day, 300, 415);
    insertPowerState($this->deviceId, '2026-08-04', 'efficient', 14400);
    insertPowerState($this->deviceId, '2026-08-04', 'idle', 600);
    insertPowerState($this->deviceId, '2026-08-04', 'off', 71400);

    $card = pithCardOn(fetchPithEvents($this, $this->teamId), '2026-08-04');

    expect($card)->toContain('pd-rail-grouped')
        ->and($card)->toContain('pd-rail-detailed')
        // All six states are always in the readout, zeros included, so the grid
        // keeps one height and days stay comparable line for line.
        ->and(substr_count($card, "class='pd-scell"))->toBe(6)
        // The three states with no duration are dimmed rather than dropped.
        ->and(substr_count($card, 'pd-scell-zero'))->toBe(3);
});

it('draws a card for a day with telemetry but no production', function () {
    insertPowerState($this->deviceId, '2026-08-06', 'missing', 40752);
    insertPowerState($this->deviceId, '2026-08-06', 'off', 45648);

    $card = pithCardOn(fetchPithEvents($this, $this->teamId), '2026-08-06');
    [, $chip] = pithHeadline($card);

    // An outage must not render as an empty column: zero and unknown are different.
    // The day says "no production" once — no dash-and-unit placeholder beside it —
    // and still draws its machine tier at full width.
    expect($chip)->toBe('no production')
        ->and($card)->not->toContain('pd-big')
        ->and($card)->toContain('pd-rail-grouped')
        ->and($card)->toContain('pd-row-head');
});

it('provides extendedProps with structured popover details', function () {
    insertPithSummary($this->teamId, $this->deviceId, '2026-08-04', $this->day, 1500, 2044);
    insertPowerState($this->deviceId, '2026-08-04', 'efficient', 28800);
    insertPowerState($this->deviceId, '2026-08-04', 'off', 55800);

    $events = fetchPithEvents($this, $this->teamId);
    $allDayEvent = collect($events)->first(fn ($e) => ($e['allDay'] ?? false) && $e['start'] === '2026-08-04');

    expect($allDayEvent)->not->toBeNull()
        ->and($allDayEvent)->toHaveKey('extendedProps');

    $props = $allDayEvent['extendedProps'];
    expect($props['date'])->toBe('2026-08-04')
        ->and($props['statusChip'])->toBe('All actual')
        ->and($props['shifts'])->toHaveCount(1)
        ->and($props['shifts'][0]['name'])->toBe('Shift 1')
        ->and($props['shifts'][0]['entered'])->toEqual(1500)
        ->and($props['shifts'][0]['metered'])->toEqual(2044)
        ->and($props['shifts'][0]['delta'])->toBe(-27)
        ->and($props['machine']['runningFormatted'])->toBe('8.0h')
        ->and($props['machine']['states'])->toHaveCount(2);
});

