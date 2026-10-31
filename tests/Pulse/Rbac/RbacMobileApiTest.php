<?php

use App\Models\Team;
use App\Models\User;
use App\Support\Pulse\TeamRolePermissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Pulse\Support\MobileApiRbacMatrix;
use Tests\Pulse\Support\RbacFixtures;

/**
 * Layer 3 — Full mobile API RBAC (pulse_team_roles + Sanctum + X-Team-ID).
 *
 * @group rbac
 */
beforeEach(function () {
    $this->teamId = RbacFixtures::team('RBAC Mobile', [
        'modules' => [
            'geofencing' => ['is_active' => true],
        ],
    ]);
    RbacFixtures::bindTeam($this->teamId);
    $this->users = RbacFixtures::usersForAllRoles($this->teamId);
});

afterEach(function () {
    RbacFixtures::forgetTeam();
});

function mobileApi(string $path): string
{
    return '/api/'.ltrim($path, '/');
}

function mobileHeaders(): array
{
    return ['X-Team-ID' => test()->teamId];
}

function mobileRequest(string $method, string $path): TestResponse
{
    $teamId = test()->teamId;
    $resolved = MobileApiRbacMatrix::resolvePath($path, $teamId);

    return test()
        ->withHeaders(mobileHeaders())
        ->json($method, mobileApi($resolved));
}

/**
 * Assert one mobile API route for a role; logs result to STDERR for CI visibility.
 */
function assertMobileApiRoute(array $route, string $role): void
{
    $teamId = test()->teamId;
    $user = test()->users[$role];
    $path = MobileApiRbacMatrix::resolvePath($route['path'], $teamId);
    $label = MobileApiRbacMatrix::rowLabel($route, $role);

    Sanctum::actingAs($user);

    $response = mobileRequest($route['method'], $route['path']);

    if (! MobileApiRbacMatrix::passesGates($user, $route['gates'])) {
        $response->assertForbidden("{$label} expected 403");
        fwrite(STDERR, "  ✓ {$label} → 403 (denied)\n");

        return;
    }

    if (isset($route['allowed_statuses'])) {
        expect($response->status())->toBeIn(
            $route['allowed_statuses'],
            "{$label} expected one of [".implode(',', $route['allowed_statuses'])."], got {$response->status()}"
        );
        fwrite(STDERR, "  ✓ {$label} → {$response->status()} (allowed)\n");

        return;
    }

    $expected = MobileApiRbacMatrix::expectedStatus($user, $route);
    $response->assertStatus($expected, "{$label} expected {$expected}");
    fwrite(STDERR, "  ✓ {$label} → {$expected} (allowed)\n");
}

dataset('mobile_api_routes', MobileApiRbacMatrix::pestDataset());

// ── Permissions payload contract ─────────────────────────────────────────────

it('returns permissions on login matching pulse_team_roles for the current role', function () {
    $staff = $this->users['staff'];
    $expected = TeamRolePermissions::roleForTeam($this->teamId, 'staff')['permissions'] ?? [];

    $this->postJson('/api/login', [
        'username' => $staff->email,
        'password' => 'password',
    ])
        ->assertSuccessful()
        ->assertJsonPath('permissions', array_values($expected));
})->group('rbac');

it('returns permissions on validate-token and refreshes after grant', function () {
    Sanctum::actingAs($this->users['admin']);

    $before = TeamRolePermissions::roleForTeam($this->teamId, 'admin')['permissions'] ?? [];

    mobileRequest('GET', 'validate-token')
        ->assertSuccessful()
        ->assertJsonPath('permissions', array_values($before));

    RbacFixtures::grant($this->teamId, 'admin', ['shift_settings:view']);

    $after = TeamRolePermissions::roleForTeam($this->teamId, 'admin')['permissions'] ?? [];

    mobileRequest('GET', 'validate-token')
        ->assertSuccessful()
        ->assertJsonPath('permissions', array_values($after));
})->group('rbac');

it('returns empty permissions for superadmin on validate-token', function () {
    $superadmin = User::factory()->create([
        'is_superadmin' => 1,
        'is_active' => 1,
        'current_team_id' => $this->teamId,
    ]);
    DB::table('team_user')->insert([
        'team_id' => $this->teamId,
        'user_id' => $superadmin->id,
        'role' => 'owner',
    ]);

    Sanctum::actingAs($superadmin);

    mobileRequest('GET', 'validate-token')
        ->assertSuccessful()
        ->assertJsonPath('permissions', []);
})->group('rbac');

// ── Full default matrix (all modules × all roles — one Pest case per row) ────

it('gates mobile API route by pulse_team_roles defaults', function (array $route, string $role) {
    assertMobileApiRoute($route, $role);
})->with('mobile_api_routes')->group('rbac');

// ── Per-module grant / revoke (matrix-driven, not role slug) ─────────────────

it('revokes pith production access when view capability is removed', function () {
    Sanctum::actingAs($this->users['supervisor']);

    mobileRequest('GET', 'pith')->assertSuccessful();

    RbacFixtures::revokePrefixes($this->teamId, 'supervisor', ['pith_production:']);

    mobileRequest('GET', 'pith')->assertForbidden();
    mobileRequest('GET', 'pith/add')->assertForbidden();
})->group('rbac');

it('grants pith production create to reader via matrix not role slug', function () {
    Sanctum::actingAs($this->users['reader']);

    mobileRequest('GET', 'pith/add')->assertForbidden();

    RbacFixtures::grant($this->teamId, 'reader', ['pith_production:create']);

    mobileRequest('GET', 'pith/add')->assertSuccessful();
})->group('rbac');

it('revokes pith blocks list when view capability is removed', function () {
    Sanctum::actingAs($this->users['reader']);

    mobileRequest('GET', 'pith-blocks')->assertSuccessful();

    RbacFixtures::revoke($this->teamId, 'reader', ['pith_blocks:view']);

    mobileRequest('GET', 'pith-blocks')->assertForbidden();
})->group('rbac');

it('grants pith blocks create to staff via matrix', function () {
    Sanctum::actingAs($this->users['staff']);

    mobileRequest('GET', 'pith-blocks/add')->assertForbidden();

    RbacFixtures::grant($this->teamId, 'staff', ['pith_blocks:create']);

    mobileRequest('GET', 'pith-blocks/add')->assertSuccessful();
})->group('rbac');

it('revokes fibre bales list when view capability is removed', function () {
    Sanctum::actingAs($this->users['reader']);

    mobileRequest('GET', 'fibre')->assertSuccessful();

    RbacFixtures::revoke($this->teamId, 'reader', ['fibre_bales:view']);

    mobileRequest('GET', 'fibre')->assertForbidden();
})->group('rbac');

it('grants fibre bales create to supervisor via matrix', function () {
    Sanctum::actingAs($this->users['supervisor']);

    mobileRequest('GET', 'fibre/add')->assertSuccessful();

    RbacFixtures::revoke($this->teamId, 'supervisor', ['fibre_bales:create']);

    mobileRequest('GET', 'fibre/add')->assertForbidden();

    RbacFixtures::grant($this->teamId, 'supervisor', ['fibre_bales:create']);

    mobileRequest('GET', 'fibre/add')->assertSuccessful();
})->group('rbac');

it('blocks inventory API when parent view is revoked', function () {
    Sanctum::actingAs($this->users['supervisor']);

    mobileRequest('GET', 'pith/inventory')->assertSuccessful();

    RbacFixtures::revoke($this->teamId, 'supervisor', [
        'pith_inventory:view',
        'pith_inventory:inventory_report',
    ]);

    mobileRequest('GET', 'pith/inventory')->assertForbidden();
    mobileRequest('POST', 'pith/surplus/store')->assertForbidden();
    mobileRequest('POST', 'pith/taken/store')->assertForbidden();
    mobileRequest('POST', 'pith/closelocationstock/1')->assertForbidden();
})->group('rbac');

it('blocks production modal API when view is revoked', function () {
    Sanctum::actingAs($this->users['supervisor']);

    mobileRequest('GET', 'pith/inventory/999999')->assertNotFound();

    RbacFixtures::revokePrefixes($this->teamId, 'supervisor', ['pith_production:']);

    mobileRequest('GET', 'pith/inventory/999999')->assertForbidden();
})->group('rbac');

it('revokes asset list when view capability is removed', function () {
    Sanctum::actingAs($this->users['admin']);

    mobileRequest('GET', 'assets')->assertSuccessful();

    RbacFixtures::revokePrefixes($this->teamId, 'admin', ['asset_tracking:']);

    mobileRequest('GET', 'assets')->assertForbidden();
})->group('rbac');

it('revokes attendance summary when view capability is removed', function () {
    Sanctum::actingAs($this->users['reader']);

    mobileRequest('GET', 'attendance')->assertSuccessful();

    RbacFixtures::revoke($this->teamId, 'reader', ['attendance:view']);

    mobileRequest('GET', 'attendance')->assertForbidden();
})->group('rbac');

it('revokes attendance edit routes when edit capability is removed', function () {
    Sanctum::actingAs($this->users['supervisor']);

    mobileRequest('GET', 'attendance/add')->assertSuccessful();

    RbacFixtures::revoke($this->teamId, 'supervisor', ['attendance:edit']);

    mobileRequest('GET', 'attendance/add')->assertForbidden();
})->group('rbac');

it('revokes manage_staff list when view capability is removed', function () {
    Sanctum::actingAs($this->users['staff']);

    mobileRequest('GET', 'staffs')->assertSuccessful();

    RbacFixtures::setPermissions($this->teamId, 'staff', [
        'attendance:view',
    ]);

    mobileRequest('GET', 'staffs')->assertForbidden();
})->group('rbac');

it('grants factory setup view to admin via matrix not role slug', function () {
    Sanctum::actingAs($this->users['admin']);

    mobileRequest('GET', 'shifts')->assertForbidden();

    RbacFixtures::grant($this->teamId, 'admin', ['shift_settings:view']);

    mobileRequest('GET', 'shifts')->assertSuccessful();
})->group('rbac');

it('uses geofence_locations.view for owner tracking endpoints not role name', function () {
    Sanctum::actingAs($this->users['owner']);

    mobileRequest('GET', 'teams/{team}/geofence-presence')->assertSuccessful();

    RbacFixtures::revoke($this->teamId, 'owner', [
        'geofence_locations:view',
        'geofence_locations:create',
        'geofence_locations:edit',
        'geofence_locations:delete',
    ]);

    mobileRequest('GET', 'teams/{team}/geofence-presence')->assertForbidden();
})->group('rbac');

it('keeps assigned geofences open to all team members for staff tracking', function () {
    Sanctum::actingAs($this->users['staff']);

    mobileRequest('GET', 'teams/{team}/geofence-locations/assigned')->assertSuccessful();
})->group('rbac');

it('uses manage_users matrix for team member policy not owner hardcode', function () {
    $team = Team::findOrFail($this->teamId);
    $admin = $this->users['admin'];

    expect(Gate::forUser($admin)->allows('addTeamMember', $team))->toBeFalse();

    RbacFixtures::grant($this->teamId, 'admin', ['manage_users:create']);

    expect(Gate::forUser($admin)->allows('addTeamMember', $team))->toBeTrue();
})->group('rbac');

it('rejects unauthenticated mobile API access', function () {
    $this->getJson(mobileApi('pith/inventory'))->assertUnauthorized();
    $this->postJson(mobileApi('pith/surplus/store'), [])->assertUnauthorized();
})->group('rbac');
