<?php

use App\Models\Pulse\GeofenceDeviceLog;
use App\Models\User;
use App\Support\Pulse\GeofencingModule;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Pulse\Support\TeamFactory;

beforeEach(function () {
    $this->teamId = TeamFactory::create('Geofence Log Factory '.uniqid(), true, [
        'modules' => [
            'geofencing' => [
                'is_active' => true,
                'max_boundaries' => GeofencingModule::defaultMaxBoundaries(),
            ],
        ],
    ]);

    $this->staff = User::factory()->create([
        'is_superadmin' => 0,
        'current_team_id' => $this->teamId,
    ]);
    DB::table('team_user')->insert([
        'team_id' => $this->teamId,
        'user_id' => $this->staff->id,
        'role' => 'staff',
    ]);

    $this->owner = User::factory()->create([
        'is_superadmin' => 0,
        'current_team_id' => $this->teamId,
    ]);
    DB::table('team_user')->insert([
        'team_id' => $this->teamId,
        'user_id' => $this->owner->id,
        'role' => 'owner',
    ]);
});

it('stores a device log from a staff phone for multi-user tracking', function () {
    Sanctum::actingAs($this->staff);

    $this->postJson("/api/teams/{$this->teamId}/geofence-logs?factory_id={$this->teamId}", [
        'step' => 'HEALTH',
        'category' => 'HEALTH_FAIL',
        'reason' => 'gps_off',
        'trackable' => false,
        'battery_percent' => 12,
        'gps_enabled' => false,
        'staff_label' => 'Ravi',
        'device_model' => 'Test Phone',
        'recorded_at' => now('Asia/Kolkata')->toIso8601String(),
    ])
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.reason', 'gps_off')
        ->assertJsonPath('data.staff_label', 'Ravi');

    expect(GeofenceDeviceLog::query()->where('team_id', $this->teamId)->count())->toBe(1);
});

it('lets owners list logs from all staff phones', function () {
    GeofenceDeviceLog::create([
        'user_id' => $this->staff->id,
        'team_id' => $this->teamId,
        'step' => 'FILTER',
        'category' => 'EVENT_SKIPPED',
        'reason' => 'still_near_boundary',
        'staff_label' => 'Ravi',
        'trackable' => true,
        'extra' => ['detail' => 'dist=50.0m need>=85.0m'],
        'recorded_at' => now('Asia/Kolkata'),
        'received_at' => now('Asia/Kolkata'),
    ]);

    Sanctum::actingAs($this->owner);

    $this->getJson("/api/teams/{$this->teamId}/geofence-logs?factory_id={$this->teamId}")
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.0.reason', 'still_near_boundary')
        ->assertJsonPath('data.0.user_id', $this->staff->id)
        ->assertJsonPath('data.0.extra.detail', 'dist=50.0m need>=85.0m');
});

it('filters problems-only and date range for owner diagnostics', function () {
    $today = now('Asia/Kolkata');

    GeofenceDeviceLog::create([
        'user_id' => $this->staff->id,
        'team_id' => $this->teamId,
        'step' => 'FILTER',
        'category' => 'EVENT_SKIPPED',
        'reason' => 'still_near_boundary',
        'staff_label' => 'Ravi',
        'trackable' => true,
        'recorded_at' => $today->copy(),
        'received_at' => $today->copy(),
    ]);

    GeofenceDeviceLog::create([
        'user_id' => $this->staff->id,
        'team_id' => $this->teamId,
        'step' => 'POST',
        'category' => 'EVENT_POSTED',
        'reason' => 'event_posted',
        'event_type' => 'ENTER',
        'staff_label' => 'Ravi',
        'trackable' => true,
        'recorded_at' => $today->copy()->subMinute(),
        'received_at' => $today->copy()->subMinute(),
    ]);

    GeofenceDeviceLog::create([
        'user_id' => $this->staff->id,
        'team_id' => $this->teamId,
        'step' => 'HEALTH',
        'category' => 'HEALTH_FAIL',
        'reason' => 'battery_optimization_enabled',
        'staff_label' => 'Ravi',
        'trackable' => false,
        'recorded_at' => $today->copy()->subDays(3),
        'received_at' => $today->copy()->subDays(3),
    ]);

    Sanctum::actingAs($this->owner);

    $problems = $this->getJson(
        "/api/teams/{$this->teamId}/geofence-logs?factory_id={$this->teamId}&problems=1&from={$today->toDateString()}&to={$today->toDateString()}"
    )
        ->assertOk()
        ->json('data');

    expect($problems)->toHaveCount(1)
        ->and($problems[0]['reason'])->toBe('still_near_boundary');
});

it('forbids staff without geofence_locations.view from listing logs', function () {
    Sanctum::actingAs($this->staff);

    $this->getJson("/api/teams/{$this->teamId}/geofence-logs?factory_id={$this->teamId}")
        ->assertForbidden();
});

it('exposes geofencing on team modules', function () {
    Sanctum::actingAs($this->staff);

    $this->getJson("/api/team/modules?factory_id={$this->teamId}")
        ->assertOk()
        ->assertJsonPath('geofencing', true)
        ->assertJsonMissing(['geofence_log_enabled']);
});
