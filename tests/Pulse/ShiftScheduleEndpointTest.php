<?php

use App\Models\Pulse\Shift;
use App\Models\User;
use App\Services\Pulse\ShiftScheduleService;
use Illuminate\Support\Facades\DB;
use Tests\Pulse\Support\TeamFactory;

function pulseShiftTestTeam(string $anchor = '08:00:00'): int
{
    $teamId = TeamFactory::create('Shift Test Factory '.uniqid());

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

beforeEach(function () {
    Shift::forgetAnchorCache();

    $this->teamId = pulseShiftTestTeam();
    $this->user = User::factory()->create([
        'is_superadmin' => 1,
        'current_team_id' => $this->teamId,
    ]);
    $this->actingAs($this->user, 'sanctum');
});

it('saves a valid three-shift day', function () {
    $response = $this->putJson('/api/shifts', ['shifts' => [
        ['name' => 'Shift 1', 'start_time' => '08:00', 'end_time' => '16:00'],
        ['name' => 'Shift 2', 'start_time' => '16:00', 'end_time' => '00:00'],
        ['name' => 'Shift 3', 'start_time' => '00:00', 'end_time' => '08:00'],
    ]]);

    $response->assertOk();
    expect(Shift::activeForTeam($this->teamId)->count())->toBe(3);
});

it('accepts back-to-back shifts where one ends exactly when the next starts', function () {
    $this->putJson('/api/shifts', ['shifts' => [
        ['name' => 'Day', 'start_time' => '08:00', 'end_time' => '18:00'],
        ['name' => 'Night', 'start_time' => '18:00', 'end_time' => '08:00'],
    ]])->assertOk();

    expect(Shift::activeForTeam($this->teamId)->count())->toBe(2);
});

it('rejects overlapping shifts with the error keyed to the offending index', function () {
    $response = $this->putJson('/api/shifts', ['shifts' => [
        ['name' => 'Day', 'start_time' => '08:00', 'end_time' => '18:00'],
        ['name' => 'Night', 'start_time' => '17:00', 'end_time' => '08:00'],
    ]]);

    $response->assertStatus(422)->assertJsonValidationErrors(['shifts.1']);
    expect(Shift::where('team_id', $this->teamId)->count())->toBe(0);
});

it('rejects a shift crossing the anchor boundary', function () {
    $this->putJson('/api/shifts', ['shifts' => [
        ['name' => 'Crossing', 'start_time' => '06:00', 'end_time' => '10:00'],
    ]])->assertStatus(422)->assertJsonValidationErrors(['shifts.0']);
});

it('rejects a fourth shift', function () {
    $this->putJson('/api/shifts', ['shifts' => [
        ['name' => 'S1', 'start_time' => '08:00', 'end_time' => '12:00'],
        ['name' => 'S2', 'start_time' => '12:00', 'end_time' => '16:00'],
        ['name' => 'S3', 'start_time' => '16:00', 'end_time' => '20:00'],
        ['name' => 'S4', 'start_time' => '20:00', 'end_time' => '00:00'],
    ]])->assertStatus(422)->assertJsonValidationErrors(['shifts']);
});

it('deactivates a removed shift instead of deleting it', function () {
    $this->putJson('/api/shifts', ['shifts' => [
        ['name' => 'Day', 'start_time' => '08:00', 'end_time' => '18:00'],
        ['name' => 'Night', 'start_time' => '18:00', 'end_time' => '08:00'],
    ]])->assertOk();

    $dayShiftId = Shift::where('team_id', $this->teamId)->where('name', 'Day')->value('id');
    $nightShiftId = Shift::where('team_id', $this->teamId)->where('name', 'Night')->value('id');

    $this->putJson('/api/shifts', ['shifts' => [
        ['id' => $dayShiftId, 'name' => 'Day', 'start_time' => '08:00', 'end_time' => '18:00'],
    ]])->assertOk();

    $nightShift = Shift::find($nightShiftId);

    expect($nightShift)->not->toBeNull()
        ->and((int) $nightShift->is_active)->toBe(0)
        ->and(Shift::activeForTeam($this->teamId)->count())->toBe(1);
});

it('validates the same schedule differently under different anchors', function () {
    $service = app(ShiftScheduleService::class);
    $schedule = [['name' => 'Day', 'start_time' => '06:00', 'end_time' => '18:00']];

    expect($service->validateSchedule($schedule, '06:00:00'))->toBe([])
        ->and($service->validateSchedule($schedule, '08:00:00'))->toHaveKey(0);
});

it('rejects an anchor change that an existing shift would cross', function () {
    $this->putJson('/api/shifts', ['shifts' => [
        ['name' => 'Day', 'start_time' => '08:00', 'end_time' => '18:00'],
    ]])->assertOk();

    $this->putJson('/api/shifts/anchor', ['shift_day_anchor' => '09:00'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['shift_day_anchor']);

    expect(Shift::anchorForTeam($this->teamId))->toBe('08:00:00');
});

it('accepts an anchor change that keeps the schedule valid', function () {
    $this->putJson('/api/shifts', ['shifts' => [
        ['name' => 'Day', 'start_time' => '09:00', 'end_time' => '18:00'],
    ]])->assertOk();

    $this->putJson('/api/shifts/anchor', ['shift_day_anchor' => '09:00'])->assertOk();

    Shift::forgetAnchorCache($this->teamId);
    expect(Shift::anchorForTeam($this->teamId))->toBe('09:00:00');
});
