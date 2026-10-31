<?php

use App\Models\Pulse\GeofenceEvent;
use App\Models\Pulse\GeofenceLocation;
use App\Models\User;
use App\Support\Pulse\GeofencingModule;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Pulse\Support\TeamFactory;

beforeEach(function () {
    $this->teamId = TeamFactory::create('Geofence Factory '.uniqid(), true, [
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

    $this->admin = User::factory()->create([
        'is_superadmin' => 0,
        'current_team_id' => $this->teamId,
    ]);
    DB::table('team_user')->insert([
        'team_id' => $this->teamId,
        'user_id' => $this->admin->id,
        'role' => 'admin',
    ]);

    $this->location = GeofenceLocation::create([
        'team_id' => $this->teamId,
        'name' => 'Factory A',
        'type' => 'factory',
        'latitude' => 11.01234567,
        'longitude' => 76.96123456,
        'radius' => 150,
        'status' => GeofenceLocation::STATUS_ACTIVE,
    ]);
});

it('returns assigned active geofences for the logged-in staff member', function () {
    Sanctum::actingAs($this->staff);

    $this->getJson("/api/teams/{$this->teamId}/geofence-locations/assigned?factory_id={$this->teamId}")
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.0.id', $this->location->id)
        ->assertJsonPath('data.0.name', 'Factory A')
        ->assertJsonPath('data.0.radius', 150)
        ->assertJsonPath('data.0.boundary_type', 'circle')
        ->assertJsonPath('data.0.polygon_coordinates', null);
});

it('stores an ENTER event and ignores a near-duplicate', function () {
    Sanctum::actingAs($this->staff);
    $recordedAt = Carbon::now('Asia/Kolkata')->toIso8601String();

    $this->postJson("/api/teams/{$this->teamId}/geofence-events?factory_id={$this->teamId}", [
        'location_id' => $this->location->id,
        'event_type' => 'ENTER',
        'latitude' => 11.0124,
        'longitude' => 76.9613,
        'recorded_at' => $recordedAt,
    ])->assertCreated()->assertJsonPath('success', true);

    $this->postJson("/api/teams/{$this->teamId}/geofence-events?factory_id={$this->teamId}", [
        'location_id' => $this->location->id,
        'event_type' => 'ENTER',
        'latitude' => 11.0124,
        'longitude' => 76.9613,
        'recorded_at' => $recordedAt,
    ])->assertOk()->assertJsonPath('duplicate', true);

    expect(GeofenceEvent::where('user_id', $this->staff->id)->count())->toBe(1);
});

it('ignores a second ENTER until an EXIT is recorded', function () {
    Sanctum::actingAs($this->staff);
    $enteredAt = Carbon::now('Asia/Kolkata');

    $this->postJson("/api/teams/{$this->teamId}/geofence-events?factory_id={$this->teamId}", [
        'location_id' => $this->location->id,
        'event_type' => 'ENTER',
        'latitude' => 11.0124,
        'longitude' => 76.9613,
        'recorded_at' => $enteredAt->toIso8601String(),
    ])->assertCreated();

    $this->postJson("/api/teams/{$this->teamId}/geofence-events?factory_id={$this->teamId}", [
        'location_id' => $this->location->id,
        'event_type' => 'ENTER',
        'latitude' => 11.0125,
        'longitude' => 76.9614,
        'recorded_at' => $enteredAt->copy()->addMinutes(6)->toIso8601String(),
    ])->assertOk()->assertJsonPath('duplicate', true);

    expect(GeofenceEvent::where('user_id', $this->staff->id)->count())->toBe(1);

    $this->postJson("/api/teams/{$this->teamId}/geofence-events?factory_id={$this->teamId}", [
        'location_id' => $this->location->id,
        'event_type' => 'EXIT',
        'latitude' => 11.0130,
        'longitude' => 76.9620,
        'recorded_at' => $enteredAt->copy()->addMinutes(7)->toIso8601String(),
    ])->assertCreated()->assertJsonPath('data.event_type', 'EXIT');

    $this->postJson("/api/teams/{$this->teamId}/geofence-events?factory_id={$this->teamId}", [
        'location_id' => $this->location->id,
        'event_type' => 'ENTER',
        'latitude' => 11.0124,
        'longitude' => 76.9613,
        'recorded_at' => $enteredAt->copy()->addMinutes(8)->toIso8601String(),
    ])->assertCreated()->assertJsonPath('data.event_type', 'ENTER');

    expect(GeofenceEvent::where('user_id', $this->staff->id)->pluck('event_type')->all())
        ->toBe(['ENTER', 'EXIT', 'ENTER']);
});

it('ignores rapid same-type jitter within five minutes', function () {
    Sanctum::actingAs($this->staff);
    $enteredAt = Carbon::now('Asia/Kolkata');

    $this->postJson("/api/teams/{$this->teamId}/geofence-events?factory_id={$this->teamId}", [
        'location_id' => $this->location->id,
        'event_type' => 'ENTER',
        'latitude' => 11.0124,
        'longitude' => 76.9613,
        'recorded_at' => $enteredAt->toIso8601String(),
    ])->assertCreated();

    $this->postJson("/api/teams/{$this->teamId}/geofence-events?factory_id={$this->teamId}", [
        'location_id' => $this->location->id,
        'event_type' => 'ENTER',
        'latitude' => 11.0125,
        'longitude' => 76.9614,
        'recorded_at' => $enteredAt->copy()->addMinutes(2)->toIso8601String(),
    ])->assertOk()->assertJsonPath('duplicate', true);

    expect(GeofenceEvent::where('user_id', $this->staff->id)->count())->toBe(1);
});

it('accepts next-day ENTER after a missed EXIT and closes the previous day', function () {
    Sanctum::actingAs($this->staff);

    $twoDaysAgo = Carbon::now('Asia/Kolkata')->subDays(2)->setTime(9, 25, 0);
    $today = Carbon::now('Asia/Kolkata')->setTime(9, 25, 0);

    GeofenceEvent::create([
        'user_id' => $this->staff->id,
        'team_id' => $this->teamId,
        'geofence_location_id' => $this->location->id,
        'event_type' => GeofenceEvent::TYPE_ENTER,
        'latitude' => 11.0124,
        'longitude' => 76.9613,
        'recorded_at' => $twoDaysAgo,
        'received_at' => $twoDaysAgo,
    ]);

    $this->postJson("/api/teams/{$this->teamId}/geofence-events?factory_id={$this->teamId}", [
        'location_id' => $this->location->id,
        'event_type' => 'ENTER',
        'latitude' => 11.0126,
        'longitude' => 76.9615,
        'recorded_at' => $today->toIso8601String(),
    ])->assertCreated()->assertJsonPath('data.event_type', 'ENTER');

    $events = GeofenceEvent::where('user_id', $this->staff->id)
        ->orderBy('recorded_at')
        ->orderBy('id')
        ->get();

    expect($events)->toHaveCount(3);
    expect($events->pluck('event_type')->all())->toBe(['ENTER', 'EXIT', 'ENTER']);
    expect($events[1]->recorded_at->toDateString())->toBe($twoDaysAgo->toDateString());
    expect($events[1]->recorded_at->format('H:i'))->toBe('23:59');
    expect($events[2]->recorded_at->isSameDay($today))->toBeTrue();

    Sanctum::actingAs($this->owner);
    $this->getJson("/api/teams/{$this->teamId}/staff/{$this->staff->id}/geofence-events?date={$today->toDateString()}&factory_id={$this->teamId}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.event_type', 'ENTER');
});

it('accepts EXIT after an open ENTER', function () {
    Sanctum::actingAs($this->staff);
    $enteredAt = Carbon::now('Asia/Kolkata')->subMinutes(30);

    GeofenceEvent::create([
        'user_id' => $this->staff->id,
        'team_id' => $this->teamId,
        'geofence_location_id' => $this->location->id,
        'event_type' => GeofenceEvent::TYPE_ENTER,
        'latitude' => 11.0124,
        'longitude' => 76.9613,
        'recorded_at' => $enteredAt,
        'received_at' => $enteredAt,
    ]);

    $this->postJson("/api/teams/{$this->teamId}/geofence-events?factory_id={$this->teamId}", [
        'location_id' => $this->location->id,
        'event_type' => 'EXIT',
        'latitude' => 11.0130,
        'longitude' => 76.9620,
        'recorded_at' => Carbon::now('Asia/Kolkata')->toIso8601String(),
    ])->assertCreated()->assertJsonPath('data.event_type', 'EXIT');

    expect(GeofenceEvent::where('user_id', $this->staff->id)->pluck('event_type')->all())
        ->toBe(['ENTER', 'EXIT']);
});

it('returns a staff member day of geofence events for the owner', function () {
    $day = Carbon::create(2026, 8, 17, 8, 42, 0, 'Asia/Kolkata');

    GeofenceEvent::create([
        'user_id' => $this->staff->id,
        'team_id' => $this->teamId,
        'geofence_location_id' => $this->location->id,
        'event_type' => GeofenceEvent::TYPE_ENTER,
        'latitude' => 11.0124,
        'longitude' => 76.9613,
        'recorded_at' => $day,
        'received_at' => $day,
    ]);
    GeofenceEvent::create([
        'user_id' => $this->staff->id,
        'team_id' => $this->teamId,
        'geofence_location_id' => $this->location->id,
        'event_type' => GeofenceEvent::TYPE_EXIT,
        'latitude' => 11.0125,
        'longitude' => 76.9614,
        'recorded_at' => $day->copy()->setTime(10, 15),
        'received_at' => $day->copy()->setTime(10, 15),
    ]);

    Sanctum::actingAs($this->owner);

    $this->getJson("/api/teams/{$this->teamId}/staff/{$this->staff->id}/geofence-events?date=2026-08-17&factory_id={$this->teamId}")
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.event_type', 'ENTER')
        ->assertJsonPath('data.0.location.name', 'Factory A')
        ->assertJsonPath('data.1.event_type', 'EXIT');
});

it('forbids an admin from reading staff geofence history', function () {
    Sanctum::actingAs($this->admin);

    $this->getJson("/api/teams/{$this->teamId}/staff/{$this->staff->id}/geofence-events?date=2026-08-17&factory_id={$this->teamId}")
        ->assertForbidden();
});

it('forbids staff from reading another user geofence history', function () {
    Sanctum::actingAs($this->staff);

    $this->getJson("/api/teams/{$this->teamId}/staff/{$this->staff->id}/geofence-events?date=2026-08-17&factory_id={$this->teamId}")
        ->assertForbidden();
});

it('stores a separate ENTER for each staff member on the same fence', function () {
    $other = User::factory()->create([
        'is_superadmin' => 0,
        'current_team_id' => $this->teamId,
    ]);
    DB::table('team_user')->insert([
        'team_id' => $this->teamId,
        'user_id' => $other->id,
        'role' => 'staff',
    ]);

    $recordedAt = Carbon::now('Asia/Kolkata')->toIso8601String();

    Sanctum::actingAs($this->staff);
    $this->postJson("/api/teams/{$this->teamId}/geofence-events?factory_id={$this->teamId}", [
        'location_id' => $this->location->id,
        'event_type' => 'ENTER',
        'latitude' => 11.0124,
        'longitude' => 76.9613,
        'recorded_at' => $recordedAt,
    ])->assertCreated();

    Sanctum::actingAs($other);
    $this->postJson("/api/teams/{$this->teamId}/geofence-events?factory_id={$this->teamId}", [
        'location_id' => $this->location->id,
        'event_type' => 'ENTER',
        'latitude' => 11.0125,
        'longitude' => 76.9614,
        'recorded_at' => $recordedAt,
    ])->assertCreated();

    expect(GeofenceEvent::where('geofence_location_id', $this->location->id)->count())->toBe(2);
});
