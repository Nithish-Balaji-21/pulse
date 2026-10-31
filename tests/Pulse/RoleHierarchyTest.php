<?php

use App\Livewire\ManageUsers;
use App\Models\Team;
use App\Models\User;
use App\Support\Pulse\TeamRolePermissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\Pulse\Support\TeamFactory;

/**
 * The factory role hierarchy, as specified.
 *
 *   reader     — all data read-only
 *   staff      — reader + pith production add/edit, pith taken add/edit, husk stock
 *   supervisor — staff + pith/taken delete, surplus, close location,
 *                fibre CRUD, pith-blocks CRUD, attendance edit
 *   admin      — supervisor + staff workers and pith locations/groups
 *   owner      — admin + user management, deletes, customization, and factory configuration
 */
beforeEach(function () {
    $this->teamId = TeamFactory::create('Role Hierarchy Factory '.uniqid());

    $this->users = [];

    foreach (['reader', 'staff', 'supervisor', 'admin', 'owner'] as $role) {
        $user = User::factory()->create([
            'is_superadmin' => 0,
            'is_active' => 1,
            'current_team_id' => $this->teamId,
        ]);

        DB::table('team_user')->insert([
            ['team_id' => $this->teamId, 'user_id' => $user->id, 'role' => $role],
        ]);

        $this->users[$role] = $user;
    }

    app()->instance('current.team', Team::find($this->teamId));
});

afterEach(function () {
    app()->forgetInstance('current.team');
});

function roleUser(string $role): User
{
    return test()->users[$role];
}

// ─── staff tier: production records ──────────────────────────────────────────

it('opens production records to staff and above, never to a reader', function (string $role, bool $expected) {
    expect(roleUser($role)->canManageRecords())->toBe($expected);
})->with([
    ['reader', false],
    ['staff', true],
    ['supervisor', true],
    ['admin', true],
    ['owner', true],
]);

// ─── supervisor tier: attendance, close location ─────────────────────────────

it('opens attendance to supervisor and above', function (string $role, bool $expected) {
    expect(roleUser($role)->canManageAttendance())->toBe($expected);
})->with([
    ['reader', false],
    ['staff', false],
    ['supervisor', true],
    ['admin', true],
    ['owner', true],
]);

it('opens attendance view to reader and above by default', function (string $role, bool $expected) {
    expect(roleUser($role)->canViewAttendance())->toBe($expected);
})->with([
    ['reader', true],
    ['staff', true],
    ['supervisor', true],
    ['admin', true],
    ['owner', true],
]);

it('opens pith inventory section via parent view for reader and above by default', function (string $role, bool $expected) {
    expect(roleUser($role)->canViewPithInventory())->toBe($expected);
})->with([
    ['reader', true],
    ['staff', true],
    ['supervisor', true],
    ['admin', true],
    ['owner', true],
]);

it('opens closing a location to supervisor and above', function (string $role, bool $expected) {
    expect(roleUser($role)->canCloseLocationStock())->toBe($expected);
})->with([
    ['reader', false],
    ['staff', false],
    ['supervisor', true],
    ['admin', true],
    ['owner', true],
]);

/**
 * Husk stock sits a tier below the rest of this block: the staff weighing the incoming husks are
 * the ones the mobile app prompts each morning, so they hold the write that prompt posts through.
 */
it('opens husk stock to staff and above', function (string $role, bool $expected) {
    expect(roleUser($role)->canManageHuskStock())->toBe($expected);
})->with([
    ['reader', false],
    ['staff', true],
    ['supervisor', true],
    ['admin', true],
    ['owner', true],
]);

it('opens supervisor production writes to supervisor and above', function (string $role, bool $expected) {
    expect(roleUser($role)->canManageSupervisorProduction())->toBe($expected);
})->with([
    ['reader', false],
    ['staff', false],
    ['supervisor', true],
    ['admin', true],
    ['owner', true],
]);

// ─── admin tier: deletion and settings ───────────────────────────────────────

it('opens delete-records to owner and customization admins', function (string $role, bool $expected) {
    expect(roleUser($role)->canDeleteRecords())->toBe($expected);
})->with([
    ['reader', false],
    ['staff', false],
    ['supervisor', false],
    ['admin', true],
    ['owner', true],
]);

it('opens settings to admin and above', function (string $role, bool $expected) {
    expect(roleUser($role)->canAccessSettings())->toBe($expected);
})->with([
    ['reader', false],
    ['staff', false],
    ['supervisor', false],
    ['admin', true],
    ['owner', true],
]);

// ─── owner tier: factory setup ───────────────────────────────────────────────

it('opens factory setup to owner only by default', function (string $role, bool $expected) {
    expect(roleUser($role)->canManageFactorySetup())->toBe($expected);
})->with([
    ['reader', false],
    ['staff', false],
    ['supervisor', false],
    ['admin', false],
    ['owner', true],
]);

// ─── user management ─────────────────────────────────────────────────────────

it('lets only an owner hand out users by default', function () {
    expect(roleUser('admin')->assignableRoles())->toBe([])
        ->and(roleUser('admin')->canAddUsers())->toBeFalse()
        ->and(roleUser('admin')->canManageUserWithRole('admin'))->toBeFalse()
        ->and(roleUser('admin')->canManageUserWithRole('staff'))->toBeFalse();

    expect(roleUser('owner')->assignableRoles())->toBe(['admin', 'supervisor', 'staff', 'reader'])
        ->and(roleUser('owner')->canManageUserWithRole('admin'))->toBeTrue();
});

it('lets nobody below admin hand out any role', function (string $role) {
    expect(roleUser($role)->assignableRoles())->toBe([])
        ->and(roleUser($role)->canAddUsers())->toBeFalse();
})->with(['reader', 'staff', 'supervisor']);

it('never lets ownership be handed out through the role form', function () {
    expect(roleUser('owner')->canManageUserWithRole('owner'))->toBeFalse()
        ->and(roleUser('admin')->canManageUserWithRole('owner'))->toBeFalse();
});

// ─── routes ──────────────────────────────────────────────────────────────────

it('keeps staff out of attendance editing', function () {
    $this->actingAs(roleUser('staff'))
        ->get("/{$this->teamId}/attendance/edit/2026-01-01")
        ->assertForbidden();
});

it('lets a supervisor into attendance editing', function () {
    $this->actingAs(roleUser('supervisor'))
        ->get("/{$this->teamId}/attendance/edit/2026-01-01")
        ->assertSuccessful();
});

it('keeps admin out of factory setup routes by default', function (string $path) {
    $this->actingAs(roleUser('admin'))
        ->get("/{$this->teamId}/{$path}")
        ->assertForbidden();
})->with(['shifts', 'assetcategories', 'settings']);

it('lets an owner into factory setup', function (string $path) {
    $this->actingAs(roleUser('owner'))
        ->get("/{$this->teamId}/{$path}")
        ->assertSuccessful();
})->with(['shifts', 'assetcategories', 'settings']);

it('keeps IoT devices superadmin-only even for an owner', function () {
    $this->actingAs(roleUser('owner'))
        ->get("/{$this->teamId}/devices")
        ->assertForbidden();
});

it('keeps staff and pith-location pages open to an admin', function (string $path) {
    $this->actingAs(roleUser('admin'))
        ->get("/{$this->teamId}/{$path}")
        ->assertSuccessful();
})->with(['staffs', 'pithlocations', 'pithlocationgroups']);

/**
 * `manage_users:view` is on the default matrix for supervisor and above (they
 * already see the Roles page via view-role-matrix), so the Users page opens
 * to them too — read-only. Creating, editing or removing a user is a
 * separate `manage_users.create/edit/delete` capability, which stays
 * owner-only by default and is enforced inside ManageUsers itself.
 */
it('opens the users page read-only to supervisor and above, never to staff or reader', function (string $role, bool $expected) {
    $this->actingAs(roleUser($role))
        ->get("/{$this->teamId}/users")
        ->assertStatus($expected ? 200 : 403);
})->with([
    ['reader', false],
    ['staff', false],
    ['supervisor', true],
    ['admin', true],
    ['owner', true],
]);

it('keeps user management mutations owner-only by default', function () {
    expect(roleUser('admin')->canManageUsers())->toBeFalse()
        ->and(roleUser('owner')->canManageUsers())->toBeTrue();
});

// ─── gates the blades and routes read ────────────────────────────────────────

it('closes the supervisor gates to staff', function () {
    $this->actingAs(roleUser('staff'));

    expect(Gate::allows('manage-attendance'))->toBeFalse()
        ->and(Gate::allows('close-location-stock'))->toBeFalse()
        ->and(Gate::allows('supervisor-production'))->toBeFalse()
        ->and(Gate::allows('manage-records'))->toBeTrue()
        // Husk stock is not a supervisor gate: staff record the daily intake themselves.
        ->and(Gate::allows('manage-husk-stock'))->toBeTrue();
});

/**
 * `supervisor-production` is a coarse OR gate (any of fibre edit/delete, pith
 * blocks edit/delete, pith production delete, pith taken delete). A
 * supervisor holds fibre_bales:edit and pith_blocks:edit by default, which is
 * enough to make the coarse gate true — but that must never be read as
 * "supervisor can delete fibre bales". Deletion stays admin+ via the
 * fine-grained fibre_bales.delete gate, which routes/api.php and
 * routes/web.php check directly for the delete endpoints.
 */
it('does not let a true supervisor-production gate imply fibre bales delete', function () {
    $this->actingAs(roleUser('supervisor'));

    expect(Gate::allows('supervisor-production'))->toBeTrue()
        ->and(Gate::allows('fibre_bales.delete'))->toBeFalse()
        ->and(Gate::allows('fibre_bales.edit'))->toBeTrue()
        ->and(Gate::allows('pith_blocks.delete'))->toBeFalse();

    $this->actingAs(roleUser('admin'));
    expect(Gate::allows('fibre_bales.delete'))->toBeTrue();
});

it('opens factory setup to an owner, not admin by default', function () {
    $this->actingAs(roleUser('admin'));
    expect(Gate::allows('manage-factory-setup'))->toBeFalse();

    $this->actingAs(roleUser('owner'));
    expect(Gate::allows('manage-factory-setup'))->toBeTrue();
});

it('turns off pith-only capabilities for a fibre factory', function () {
    $teamId = TeamFactory::create('Fibre Hierarchy Factory '.uniqid(), false, [
        'modules' => [
            'fibre' => ['is_active' => true, 'submodules' => ['weight' => true]],
            'attendance' => ['is_active' => true],
        ],
    ]);

    $admin = User::factory()->create([
        'is_superadmin' => 0,
        'is_active' => 1,
        'current_team_id' => $teamId,
    ]);

    DB::table('team_user')->insert([
        'team_id' => $teamId,
        'user_id' => $admin->id,
        'role' => 'admin',
    ]);

    app()->instance('current.team', Team::find($teamId));
    TeamRolePermissions::syncOnModuleChange($teamId);

    expect($admin->canManagePithLocationOperations())->toBeFalse()
        ->and($admin->canManageHuskStock())->toBeFalse()
        ->and($admin->canCloseLocationStock())->toBeFalse()
        ->and($admin->canManageSupervisorProduction())->toBeFalse()
        ->and(TeamRolePermissions::allows($teamId, 'admin', 'fibre_bales', 'create'))->toBeFalse()
        ->and(TeamRolePermissions::allows($teamId, 'admin', 'pith_production', 'create'))->toBeFalse();

    $this->actingAs($admin)
        ->get("/{$teamId}/pithlocations")
        ->assertForbidden();
});

// ─── ownership transfer ──────────────────────────────────────────────────────

/**
 * Ownership moves through its own flow, which demotes the outgoing owner to
 * admin — it is never handed out through the role form.
 */
it('lets only a superadmin transfer ownership', function (string $role, bool $expected) {
    expect(roleUser($role)->canTransferOwnership())->toBe($expected);
})->with([
    ['reader', false],
    ['staff', false],
    ['supervisor', false],
    ['admin', false],
    ['owner', false],
]);

it('lets a superadmin transfer ownership', function () {
    $superadmin = User::factory()->create([
        'is_superadmin' => 1,
        'is_active' => 1,
        'current_team_id' => $this->teamId,
    ]);

    expect($superadmin->canTransferOwnership())->toBeTrue();
});

/*
 * 'keeps the role help table honest' stood here. It cross-checked the capability methods
 * against ManageUsers::$roleFeatures — a hand-written table of what each role could do,
 * shown in the Role Comparison modal, which could drift from what the code enforced.
 *
 * That table went when the app moved to the role matrix: the modal is built from the same
 * permissions the capability methods read, so there is no second copy left to disagree. Each
 * capability it covered is asserted per role above — canManageRecords, canManageAttendance,
 * canDeleteRecords, canAccessSettings, canManageFactorySetup and canTransferOwnership — and
 * its one remaining column, 'View data', asserted fn () => true against every role.
 */

it('seeds five roles per factory', function () {
    $roles = TeamRolePermissions::rolesForTeam($this->teamId);
    expect($roles)->toHaveCount(5);
    expect(collect($roles)->pluck('key')->all())->toEqual(['owner', 'admin', 'supervisor', 'staff', 'reader']);
});
