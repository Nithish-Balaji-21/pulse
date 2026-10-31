<?php

use App\Models\Pulse\Shift;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Pulse\Support\TeamFactory;

function iotPithTeam(string $anchor): int
{
    $teamId = TeamFactory::create('Pith Insights Factory '.uniqid(), true, [
        'pulse' => [
            'modules' => [
                'pith' => [
                    'is_active' => true,
                    'submodules' => ['bales' => true, 'blocks' => true],
                ],
            ],
        ],
    ]);

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

function insertBlockReading(int $deviceId, string $datetime, int $counter): void
{
    DB::table('pulse_device_data_pith_blocks')->insert([
        'device_id' => $deviceId,
        'block_date' => substr($datetime, 0, 10),
        'block_datetime' => $datetime,
        'blocks_counter' => $counter,
        'is_duplicate' => 0,
        'created_at' => $datetime,
        'updated_at' => $datetime,
    ]);
}

function insertBlockSummary(int $teamId, int $deviceId, string $shiftDate, int $blocks, int $produced = 0): void
{
    DB::table('pulse_summary_pith_blocks')->insert([
        'team_id' => $teamId,
        'device_id' => $deviceId,
        'shift_date' => $shiftDate,
        'blocks_produced' => $produced,
        'blocks_expected' => $blocks,
    ]);
}

function insertMiniBaleReading(int $deviceId, string $datetime, int $counter, ?int $shiftId = null): void
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

function insertMiniBaleSummary(int $teamId, int $deviceId, string $shiftDate, int $bales, int $produced = 0): void
{
    DB::table('pulse_summary_mini_bales')->insert([
        'team_id' => $teamId,
        'device_id' => $deviceId,
        'shift_date' => $shiftDate,
        'bales_produced' => $produced,
        'bales_expected' => $bales,
    ]);
}

/**
 * @return array<string, int>
 */
function pithChipsOn(array $events, string $date, string $unit = 'blocks'): array
{
    $chips = [];

    foreach ($events as $event) {
        if (! ($event['allDay'] ?? false) || $event['start'] !== $date) {
            continue;
        }

        preg_match("/title='([^']+) Production'/", $event['title'], $label);
        preg_match("/ad-total-value'>([\d,]+)</", $event['title'], $quantity);

        if ($label && $quantity) {
            $chips[$label[1]] = (int) str_replace(',', '', $quantity[1]);
        }
    }

    return $chips;
}

beforeEach(function () {
    Shift::forgetAnchorCache();

    $this->travelTo('2026-08-08 11:30:00');

    $this->teamId = iotPithTeam('07:00:00');
    $this->deviceId = 880000 + random_int(1, 9999);

    DB::table('pulse_devices')->insert([
        'team_id' => $this->teamId,
        'device_id' => $this->deviceId,
        'module' => 'blocks',
        'status' => 'active',
    ]);

    $this->user = User::factory()->create([
        'is_superadmin' => 1,
        'current_team_id' => $this->teamId,
    ]);
    $this->actingAs($this->user);
});

it('attributes live pith blocks to shifts without an Others group', function () {
    Shift::create(['team_id' => $this->teamId, 'name' => 'Day', 'start_time' => '07:00:00', 'end_time' => '18:00:00', 'is_active' => 1]);
    Shift::create(['team_id' => $this->teamId, 'name' => 'Night', 'start_time' => '18:00:00', 'end_time' => '07:00:00', 'is_active' => 1]);

    insertBlockSummary($this->teamId, $this->deviceId, '2026-08-07', 70);

    insertBlockReading($this->deviceId, '2026-08-08 07:30:00', 140);
    insertBlockReading($this->deviceId, '2026-08-08 11:00:00', 159);

    $response = $this->getJson("/{$this->teamId}/iot-pith-blocks/fetch-events?start=2026-08-03&end=2026-08-09")
        ->assertOk()
        ->json();

    expect(pithChipsOn($response['events'], '2026-08-08', 'blocks'))->toBe(['Blocks' => 20])
        ->and($response['totals'])->not->toHaveKey('Others');
});

it('gives live pith-block gap leftovers to the nearer shift', function () {
    $this->travelTo('2026-08-08 21:00:00');

    Shift::create(['team_id' => $this->teamId, 'name' => 'Day', 'start_time' => '07:00:00', 'end_time' => '18:00:00', 'is_active' => 1]);
    Shift::create(['team_id' => $this->teamId, 'name' => 'Night', 'start_time' => '20:00:00', 'end_time' => '07:00:00', 'is_active' => 1]);

    insertBlockReading($this->deviceId, '2026-08-08 07:30:00', 1);
    insertBlockReading($this->deviceId, '2026-08-08 17:00:00', 5);
    insertBlockReading($this->deviceId, '2026-08-08 19:00:00', 6);
    insertBlockReading($this->deviceId, '2026-08-08 20:30:00', 7);
    insertBlockReading($this->deviceId, '2026-08-08 20:45:00', 8);

    $response = $this->getJson("/{$this->teamId}/iot-pith-blocks/fetch-events?start=2026-08-03&end=2026-08-09")
        ->assertOk()
        ->json();

    expect(pithChipsOn($response['events'], '2026-08-08', 'blocks'))->toBe(['Blocks' => 8])
        ->and($response['totals'])->not->toHaveKey('Others');
});

it('builds pith-block chips from raw readings when summary rows are missing', function () {
    Shift::create(['team_id' => $this->teamId, 'name' => 'Day', 'start_time' => '07:00:00', 'end_time' => '18:00:00', 'is_active' => 1]);
    Shift::create(['team_id' => $this->teamId, 'name' => 'Night', 'start_time' => '18:00:00', 'end_time' => '07:00:00', 'is_active' => 1]);

    // Historical day with raw counters only — no pulse_summary_pith_blocks rows.
    insertBlockReading($this->deviceId, '2026-08-03 09:00:00', 1);
    insertBlockReading($this->deviceId, '2026-08-03 10:00:00', 20);
    insertBlockReading($this->deviceId, '2026-08-03 19:00:00', 21);
    insertBlockReading($this->deviceId, '2026-08-03 20:00:00', 30);

    $response = $this->getJson("/{$this->teamId}/iot-pith-blocks/fetch-events?start=2026-08-03&end=2026-08-09")
        ->assertOk()
        ->json();

    // counterSpan: Day 20-1 (+1) = 20, Night 30-21 (+1) = 10
    expect(pithChipsOn($response['events'], '2026-08-03', 'blocks'))->toBe(['Blocks' => 30])
        ->and($response['totals'])->toHaveKey('Blocks')
        ->and($response['totals']['Blocks']['blocks_expected'])->toBe(30)
        ->and($response['totals'])->not->toHaveKey('Others');
});

it('shows IoT expected on pith-block chips when produced is still empty', function () {
    Shift::create(['team_id' => $this->teamId, 'name' => 'Day', 'start_time' => '07:00:00', 'end_time' => '18:00:00', 'is_active' => 1]);

    insertBlockSummary($this->teamId, $this->deviceId, '2026-08-07', 701);

    $response = $this->getJson("/{$this->teamId}/iot-pith-blocks/fetch-events?start=2026-08-03&end=2026-08-09")
        ->assertOk()
        ->json();

    expect(pithChipsOn($response['events'], '2026-08-07', 'blocks'))->toBe(['Blocks' => 701])
        ->and($response['totals']['Blocks']['blocks_expected'])->toBe(701)
        ->and($response['totals']['Blocks']['blocks_produced'])->toBe(0);
});

it('returns density buckets for pith blocks at 30m', function () {
    Shift::create(['team_id' => $this->teamId, 'name' => 'Day', 'start_time' => '07:00:00', 'end_time' => '18:00:00', 'is_active' => 1]);

    insertBlockReading($this->deviceId, '2026-08-08 08:05:00', 1);
    insertBlockReading($this->deviceId, '2026-08-08 08:10:00', 2);
    insertBlockReading($this->deviceId, '2026-08-08 08:40:00', 3);

    $response = $this->getJson("/{$this->teamId}/iot-pith-blocks/fetch-events?start=2026-08-03&end=2026-08-09&mode=density&bucket=1800")
        ->assertOk()
        ->json();

    expect($response['density'])->not->toBeNull()
        ->and($response['density']['bucketSeconds'])->toBe(1800)
        ->and($response['density']['scale'])->toBeGreaterThan(0)
        ->and(collect($response['events'])->where('classNames', 'ec-bucket')->count())->toBeGreaterThan(0);
});

it('attributes live mini-bales to shifts without an Others group', function () {
    Shift::create(['team_id' => $this->teamId, 'name' => 'Day', 'start_time' => '07:00:00', 'end_time' => '18:00:00', 'is_active' => 1]);
    Shift::create(['team_id' => $this->teamId, 'name' => 'Night', 'start_time' => '18:00:00', 'end_time' => '07:00:00', 'is_active' => 1]);

    insertMiniBaleSummary($this->teamId, $this->deviceId, '2026-08-07', 70);

    insertMiniBaleReading($this->deviceId, '2026-08-08 07:30:00', 140);
    insertMiniBaleReading($this->deviceId, '2026-08-08 11:00:00', 159);

    $response = $this->getJson("/{$this->teamId}/iot-fibre-minibales/fetch-events?start=2026-08-03&end=2026-08-09")
        ->assertOk()
        ->json();

    expect(pithChipsOn($response['events'], '2026-08-08', 'bales'))->toBe(['Bales' => 20])
        ->and($response['totals'])->not->toHaveKey('Others');
});

it('returns density buckets for mini-bales at 30m', function () {
    Shift::create(['team_id' => $this->teamId, 'name' => 'Day', 'start_time' => '07:00:00', 'end_time' => '18:00:00', 'is_active' => 1]);

    insertMiniBaleReading($this->deviceId, '2026-08-08 08:05:00', 1);
    insertMiniBaleReading($this->deviceId, '2026-08-08 08:10:00', 2);
    insertMiniBaleReading($this->deviceId, '2026-08-08 08:40:00', 3);

    $response = $this->getJson("/{$this->teamId}/iot-fibre-minibales/fetch-events?start=2026-08-03&end=2026-08-09&mode=density&bucket=1800")
        ->assertOk()
        ->json();

    expect($response['density'])->not->toBeNull()
        ->and($response['density']['bucketSeconds'])->toBe(1800)
        ->and(collect($response['events'])->where('classNames', 'ec-bucket')->count())->toBeGreaterThan(0);
});
