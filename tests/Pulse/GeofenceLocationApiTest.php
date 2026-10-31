<?php

use App\Models\Pulse\GeofenceEvent;
use App\Models\Pulse\GeofenceLocation;
use App\Models\User;
use App\Support\Pulse\GeofencingModule;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Pulse\Support\TeamFactory;

beforeEach(function () {
    $this->teamId = TeamFactory::create('Geofence Locations '.uniqid(), true, [
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

    $this->admin = User::factory()->create([
        'is_superadmin' => 0,
        'current_team_id' => $this->teamId,
    ]);
    DB::table('team_user')->insert([
        'team_id' => $this->teamId,
        'user_id' => $this->admin->id,
        'role' => 'admin',
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

    $this->api = fn (string $path) => "/api/teams/{$this->teamId}{$path}?factory_id={$this->teamId}";
});

it('lets the owner create a circle location that every staff member receives', function () {
    Sanctum::actingAs($this->owner);

    $this->postJson(($this->api)('/geofence-locations'), [
        'name' => 'Material Shop',
        'type' => 'material_shop',
        'boundary_type' => 'circle',
        'latitude' => 11.01,
        'longitude' => 76.96,
        'radius' => 80,
    ])->assertCreated()
        ->assertJsonPath('data.name', 'Material Shop')
        ->assertJsonPath('data.boundary_type', 'circle')
        ->assertJsonPath('data.radius', 80)
        ->assertJsonMissingPath('data.staff_ids');

    Sanctum::actingAs($this->staff);
    $this->getJson(($this->api)('/geofence-locations/assigned'))
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Material Shop');
});

it('lets the owner create a polygon location', function () {
    Sanctum::actingAs($this->owner);

    $this->postJson(($this->api)('/geofence-locations'), [
        'name' => 'Factory B',
        'type' => 'factory',
        'boundary_type' => 'polygon',
        'polygon_coordinates' => [
            ['lat' => 11.013, 'lng' => 76.960],
            ['lat' => 11.013, 'lng' => 76.962],
            ['lat' => 11.011, 'lng' => 76.962],
            ['lat' => 11.011, 'lng' => 76.960],
        ],
    ])->assertCreated()
        ->assertJsonPath('data.boundary_type', 'polygon')
        ->assertJsonCount(4, 'data.polygon_coordinates');

    Sanctum::actingAs($this->staff);
    $this->getJson(($this->api)('/geofence-locations/assigned'))
        ->assertOk()
        ->assertJsonPath('data.0.boundary_type', 'polygon')
        ->assertJsonCount(4, 'data.0.polygon_coordinates');
});

it('rejects a self-intersecting polygon', function () {
    Sanctum::actingAs($this->owner);

    $this->postJson(($this->api)('/geofence-locations'), [
        'name' => 'Bad Plot',
        'type' => 'factory',
        'boundary_type' => 'polygon',
        'polygon_coordinates' => [
            ['lat' => 11.00, 'lng' => 76.00],
            ['lat' => 11.02, 'lng' => 76.02],
            ['lat' => 11.00, 'lng' => 76.02],
            ['lat' => 11.02, 'lng' => 76.00],
        ],
    ])->assertStatus(422);
});

it('forbids staff and admin from creating locations', function () {
    Sanctum::actingAs($this->staff);
    $this->getJson(($this->api)('/geofence-locations'))->assertForbidden();
    $this->postJson(($this->api)('/geofence-locations'), [
        'name' => 'Nope',
        'type' => 'factory',
        'boundary_type' => 'circle',
        'latitude' => 11.01,
        'longitude' => 76.96,
        'radius' => 50,
    ])->assertForbidden();

    Sanctum::actingAs($this->admin);
    $this->postJson(($this->api)('/geofence-locations'), [
        'name' => 'Admin Nope',
        'type' => 'factory',
        'boundary_type' => 'circle',
        'latitude' => 11.01,
        'longitude' => 76.96,
        'radius' => 50,
    ])->assertForbidden();
});

it('deactivates a location so staff no longer receive it', function () {
    Sanctum::actingAs($this->owner);

    $id = $this->postJson(($this->api)('/geofence-locations'), [
        'name' => 'Supplier Yard',
        'type' => 'supplier',
        'boundary_type' => 'circle',
        'latitude' => 11.02,
        'longitude' => 76.97,
        'radius' => 40,
    ])->assertCreated()->json('data.id');

    $this->postJson(($this->api)("/geofence-locations/{$id}/deactivate"))
        ->assertOk()
        ->assertJsonPath('data.status', 'inactive');

    Sanctum::actingAs($this->staff);
    $this->getJson(($this->api)('/geofence-locations/assigned'))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('returns the same active fences to every staff member', function () {
    Sanctum::actingAs($this->owner);
    $this->postJson(($this->api)('/geofence-locations'), [
        'name' => 'Shop',
        'type' => 'material_shop',
        'boundary_type' => 'circle',
        'latitude' => 11.03,
        'longitude' => 76.98,
        'radius' => 30,
    ])->assertCreated();

    $other = User::factory()->create([
        'is_superadmin' => 0,
        'current_team_id' => $this->teamId,
    ]);
    DB::table('team_user')->insert([
        'team_id' => $this->teamId,
        'user_id' => $other->id,
        'role' => 'staff',
    ]);

    Sanctum::actingAs($this->staff);
    $this->getJson(($this->api)('/geofence-locations/assigned'))
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Shop');

    Sanctum::actingAs($other);
    $this->getJson(($this->api)('/geofence-locations/assigned'))
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Shop');
});

it('rejects a new location once the factory cap is reached', function () {
    DB::table('team_pulse')->where('team_id', $this->teamId)->update([
        'modules' => json_encode([
            'pulse' => [
                'is_active' => true,
                'modules' => [
                    'geofencing' => ['is_active' => true, 'max_boundaries' => 1],
                ],
            ],
        ]),
    ]);

    Sanctum::actingAs($this->owner);
    $this->postJson(($this->api)('/geofence-locations'), [
        'name' => 'First',
        'type' => 'factory',
        'boundary_type' => 'circle',
        'latitude' => 11.04,
        'longitude' => 76.99,
        'radius' => 25,
    ])->assertCreated();

    $this->postJson(($this->api)('/geofence-locations'), [
        'name' => 'Second',
        'type' => 'factory',
        'boundary_type' => 'circle',
        'latitude' => 11.05,
        'longitude' => 76.99,
        'radius' => 25,
    ])->assertStatus(422);
});

it('forbids geofence APIs when the add-on is off', function () {
    $teamId = TeamFactory::create('No Geofencing '.uniqid());
    $owner = User::factory()->create([
        'is_superadmin' => 0,
        'current_team_id' => $teamId,
    ]);
    DB::table('team_user')->insert([
        'team_id' => $teamId,
        'user_id' => $owner->id,
        'role' => 'owner',
    ]);

    Sanctum::actingAs($owner);
    $this->getJson("/api/teams/{$teamId}/geofence-locations?factory_id={$teamId}")
        ->assertForbidden();
});

it('deletes a location and its events', function () {
    Sanctum::actingAs($this->owner);
    $id = $this->postJson(($this->api)('/geofence-locations'), [
        'name' => 'Keep History',
        'type' => 'factory',
        'boundary_type' => 'circle',
        'latitude' => 11.04,
        'longitude' => 76.99,
        'radius' => 25,
    ])->json('data.id');

    GeofenceEvent::create([
        'user_id' => $this->staff->id,
        'team_id' => $this->teamId,
        'geofence_location_id' => $id,
        'event_type' => GeofenceEvent::TYPE_ENTER,
        'latitude' => 11.04,
        'longitude' => 76.99,
        'recorded_at' => now(),
        'received_at' => now(),
    ]);

    $this->deleteJson(($this->api)("/geofence-locations/{$id}"))
        ->assertOk()
        ->assertJsonPath('deleted', true);

    expect(GeofenceLocation::find($id))->toBeNull();
    expect(GeofenceEvent::query()->where('geofence_location_id', $id)->exists())->toBeFalse();
});
