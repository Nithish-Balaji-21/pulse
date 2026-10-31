<?php

use App\Livewire\Pulse\ManageGeofences;
use App\Models\Pulse\GeofenceLocation;
use App\Models\Team;
use App\Models\User;
use App\Support\Pulse\GeofencingModule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Pulse\Support\TeamFactory;

function geofencePageTeam(bool $enabled = true, int $maxBoundaries = 5): int
{
    return TeamFactory::create('Geofence Page '.uniqid(), true, [
        'modules' => [
            'geofencing' => [
                'is_active' => $enabled,
                'max_boundaries' => $maxBoundaries,
            ],
        ],
    ]);
}

function bindGeofenceTeam(int $teamId): Team
{
    $team = Team::with('pulse')->findOrFail($teamId);
    app()->instance('current.team', $team);

    return $team;
}

function geofenceOwner(int $teamId): User
{
    $user = User::factory()->create([
        'is_superadmin' => 0,
        'current_team_id' => $teamId,
    ]);
    DB::table('team_user')->insert([
        'team_id' => $teamId,
        'user_id' => $user->id,
        'role' => 'owner',
    ]);

    return $user;
}

afterEach(function () {
    app()->forgetInstance('current.team');
    Cache::flush();
});

it('forbids the geofence page when the add-on is off', function () {
    $teamId = geofencePageTeam(false);
    bindGeofenceTeam($teamId);
    $this->actingAs(geofenceOwner($teamId));

    Livewire::test(ManageGeofences::class)->assertForbidden();

    $this->get('/'.$teamId.'/geofences')->assertForbidden();
});

it('lets the owner create a circle and a polygon without a staff picker', function () {
    $teamId = geofencePageTeam(true);
    bindGeofenceTeam($teamId);
    $this->actingAs(geofenceOwner($teamId));

    Livewire::test(ManageGeofences::class)
        ->assertSuccessful()
        ->assertSee('Boundaries used:')
        ->assertSee('Search place')
        ->assertSee('Satellite')
        ->assertDontSee('Assign staff')
        ->assertDontSee('staff_ids')
        ->call('startCreate', 'circle')
        ->set('form.name', 'Main Gate')
        ->set('form.type', 'factory')
        ->set('form.boundary_type', 'circle')
        ->set('form.latitude', 11.01)
        ->set('form.longitude', 76.96)
        ->set('form.radius', 80)
        ->call('save')
        ->assertHasNoErrors()
        ->call('startCreate', 'polygon')
        ->set('form.name', 'Loading Bay')
        ->set('form.type', 'material_shop')
        ->set('form.boundary_type', 'polygon')
        ->set('form.polygon_coordinates', [
            ['lat' => 11.0100, 'lng' => 76.9600],
            ['lat' => 11.0105, 'lng' => 76.9600],
            ['lat' => 11.0105, 'lng' => 76.9608],
            ['lat' => 11.0100, 'lng' => 76.9608],
        ])
        ->call('save')
        ->assertHasNoErrors();

    expect(GeofenceLocation::where('team_id', $teamId)->count())->toBe(2)
        ->and(GeofenceLocation::where('team_id', $teamId)->where('boundary_type', 'polygon')->exists())->toBeTrue();
});

it('disables new creates at the stored cap', function () {
    $teamId = geofencePageTeam(true, 1);
    bindGeofenceTeam($teamId);
    $this->actingAs(geofenceOwner($teamId));

    GeofenceLocation::create([
        'team_id' => $teamId,
        'name' => 'Existing',
        'type' => 'factory',
        'boundary_type' => 'circle',
        'latitude' => 11.02,
        'longitude' => 76.97,
        'radius' => 40,
        'status' => 'active',
    ]);

    Livewire::test(ManageGeofences::class)
        ->assertSet('usedBoundaries', 1)
        ->assertSet('maxBoundaries', 1)
        ->assertDontSee('Add circle')
        ->call('startCreate', 'circle')
        ->assertSet('errorMessage', fn ($message) => is_string($message) && str_contains($message, 'maximum'))
        ->set('form.name', 'Overflow')
        ->set('form.type', 'factory')
        ->set('form.boundary_type', 'circle')
        ->set('form.latitude', 11.03)
        ->set('form.longitude', 76.98)
        ->set('form.radius', 40)
        ->call('save')
        ->assertHasErrors('form.name');

    expect(GeofenceLocation::where('team_id', $teamId)->count())->toBe(1);
});

it('forbids admin and staff from opening the editor', function () {
    $teamId = geofencePageTeam(true);
    bindGeofenceTeam($teamId);

    $admin = User::factory()->create([
        'is_superadmin' => 0,
        'current_team_id' => $teamId,
    ]);
    DB::table('team_user')->insert([
        'team_id' => $teamId,
        'user_id' => $admin->id,
        'role' => 'admin',
    ]);

    $this->actingAs($admin);
    Livewire::test(ManageGeofences::class)->assertForbidden();

    $staff = User::factory()->create([
        'is_superadmin' => 0,
        'current_team_id' => $teamId,
    ]);
    DB::table('team_user')->insert([
        'team_id' => $teamId,
        'user_id' => $staff->id,
        'role' => 'staff',
    ]);

    $this->actingAs($staff);
    Livewire::test(ManageGeofences::class)->assertForbidden();
});

it('lets a superadmin manage boundaries on an enabled factory', function () {
    $teamId = geofencePageTeam(true);
    bindGeofenceTeam($teamId);
    $superadmin = User::factory()->create([
        'is_superadmin' => 1,
        'current_team_id' => $teamId,
    ]);
    $this->actingAs($superadmin);

    Livewire::test(ManageGeofences::class)
        ->call('startCreate', 'circle')
        ->set('form.name', 'Yard')
        ->set('form.type', 'other')
        ->set('form.boundary_type', 'circle')
        ->set('form.latitude', 10.72)
        ->set('form.longitude', 77.08)
        ->set('form.radius', 50)
        ->call('save')
        ->assertHasNoErrors();

    expect(GeofenceLocation::where('team_id', $teamId)->where('name', 'Yard')->exists())->toBeTrue();
});

it('uses the configured default max rather than a hard-coded five on a new factory', function () {
    $teamId = geofencePageTeam(true, GeofencingModule::defaultMaxBoundaries());
    bindGeofenceTeam($teamId);
    $this->actingAs(geofenceOwner($teamId));

    Livewire::test(ManageGeofences::class)
        ->assertSet('maxBoundaries', GeofencingModule::defaultMaxBoundaries());
});

it('lets the owner search places through the nominatim proxy', function () {
    Http::fake([
        'nominatim.openstreetmap.org/search*' => Http::response([
            [
                'display_name' => 'Pollachi, Tamil Nadu, India',
                'lat' => '10.658',
                'lon' => '77.008',
            ],
        ], 200),
    ]);

    $teamId = geofencePageTeam(true);
    bindGeofenceTeam($teamId);
    $this->actingAs(geofenceOwner($teamId));

    $this->getJson('/'.$teamId.'/geofences/search?q=Pollachi')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.0.label', 'Pollachi, Tamil Nadu, India')
        ->assertJsonPath('data.0.lat', 10.658)
        ->assertJsonPath('data.0.lng', 77.008);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'nominatim.openstreetmap.org/search')
        && $request['q'] === 'Pollachi'
        && $request['countrycodes'] === 'in');
});

it('forbids geofence place search when the add-on is off', function () {
    Http::fake();

    $teamId = geofencePageTeam(false);
    bindGeofenceTeam($teamId);
    $this->actingAs(geofenceOwner($teamId));

    $this->getJson('/'.$teamId.'/geofences/search?q=Pollachi')->assertForbidden();
    Http::assertNothingSent();
});

it('forbids admin from using geofence place search', function () {
    Http::fake();

    $teamId = geofencePageTeam(true);
    bindGeofenceTeam($teamId);
    $admin = User::factory()->create([
        'is_superadmin' => 0,
        'current_team_id' => $teamId,
    ]);
    DB::table('team_user')->insert([
        'team_id' => $teamId,
        'user_id' => $admin->id,
        'role' => 'admin',
    ]);

    $this->actingAs($admin);
    $this->getJson('/'.$teamId.'/geofences/search?q=Pollachi')->assertForbidden();
    Http::assertNothingSent();
});
