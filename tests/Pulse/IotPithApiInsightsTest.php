<?php

use App\Models\Pulse\Shift;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Pulse\Support\TeamFactory;

beforeEach(function () {
    Shift::forgetAnchorCache();

    $this->travelTo('2026-08-08 11:30:00');

    $this->teamId = TeamFactory::create('Pith API Insights '.uniqid());

    DB::table('pulse_settings')->insert([
        'team_id' => $this->teamId,
        'module' => 'shifts',
        'key' => Shift::ANCHOR_SETTING_KEY,
        'text' => 'Shift day anchor (start of the 24-hour production day)',
        'value' => '07:00:00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->deviceId = 990000 + random_int(1, 9999);

    DB::table('pulse_devices')->insert([
        'team_id' => $this->teamId,
        'device_id' => $this->deviceId,
        'module' => 'pith',
        'status' => 'active',
    ]);

    $this->shift = Shift::create([
        'team_id' => $this->teamId,
        'name' => 'Day',
        'start_time' => '07:00:00',
        'end_time' => '18:00:00',
        'is_active' => 1,
    ]);

    $this->user = User::factory()->create([
        'is_superadmin' => 1,
        'current_team_id' => $this->teamId,
    ]);
});

function insertApiPithSummary(
    int $teamId,
    int $deviceId,
    Shift $shift,
    string $shiftDate,
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
        'eb_units' => 0,
        'eb_price' => 0,
    ]);
}

it('returns cft expected and produced with hasActuals when actuals are entered', function () {
    insertApiPithSummary($this->teamId, $this->deviceId, $this->shift, '2026-08-08', 280.5, 320.0);

    Sanctum::actingAs($this->user);

    $response = $this->getJson('/api/iot-pith/insights?start=2026-08-08')->assertOk();

    expect((float) $response->json('cftExpected'))->toBe(320.0)
        ->and((float) $response->json('cftProduced'))->toBe(280.5)
        ->and($response->json('hasActuals'))->toBeTrue();
});

it('sets hasActuals false when cft produced is zero', function () {
    insertApiPithSummary($this->teamId, $this->deviceId, $this->shift, '2026-08-08', 0, 320.0);

    Sanctum::actingAs($this->user);

    $response = $this->getJson('/api/iot-pith/insights?start=2026-08-08')->assertOk();

    expect((float) $response->json('cftExpected'))->toBe(320.0)
        ->and((float) $response->json('cftProduced'))->toBe(0.0)
        ->and($response->json('hasActuals'))->toBeFalse();
});
