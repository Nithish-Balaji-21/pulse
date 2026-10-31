<?php

/**
 * Gate::before module.action resolver spot-checks.
 *
 * Prefer the exhaustive suite: tests/Pulse/Rbac/RbacPermissionEngineTest.php
 * (php artisan test tests/Pulse/Rbac --group=rbac).
 */

use App\Models\Pulse\PulseTeamRole;
use App\Models\Team;
use App\Models\User;
use App\Support\Pulse\TeamRolePermissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\Pulse\Support\TeamFactory;

/**
 * Exercises the generic `module.action` resolver registered by
 * AuthServiceProvider's Gate::before: any ability shaped like
 * `pith_production.create` should defer to User::allows() against the
 * catalog in config('pulse_role_permissions.modules'), with no per-gate
 * wiring needed for each of the ~30 modules.
 */
beforeEach(function () {
    $this->teamId = TeamFactory::create('Capability Gate Factory '.uniqid());
    app()->instance('current.team', Team::find($this->teamId));
});

afterEach(function () {
    app()->forgetInstance('current.team');
});

function seedRolePermissions(int $teamId, string $roleKey, array $permissions): void
{
    $role = PulseTeamRole::where('team_id', $teamId)->where('key', $roleKey)->firstOrFail();
    $role->update(['permissions' => $permissions]);
    TeamRolePermissions::forgetCache($teamId);
}

it('grants exactly the capability a role was given, and nothing wider', function () {
    $staff = User::factory()->create([
        'is_superadmin' => 0,
        'is_active' => 1,
        'current_team_id' => $this->teamId,
    ]);

    DB::table('team_user')->insert([
        'team_id' => $this->teamId,
        'user_id' => $staff->id,
        'role' => 'staff',
    ]);

    // Staff holds fibre_bales:edit only — no create, no delete.
    seedRolePermissions($this->teamId, 'staff', ['fibre_bales:edit']);

    $this->actingAs($staff);

    expect(Gate::allows('fibre_bales.edit'))->toBeTrue()
        ->and(Gate::allows('fibre_bales.create'))->toBeFalse()
        ->and(Gate::allows('fibre_bales.delete'))->toBeFalse();

    // The coarse legacy OR gate reads as true purely because of the edit
    // capability — it must never be mistaken for delete access.
    expect(Gate::allows('supervisor-production'))->toBeTrue()
        ->and(Gate::allows('fibre_bales.delete'))->toBeFalse();
});

it('resolves module.action gates through Gate::before for every role', function (string $role, bool $expected) {
    $user = User::factory()->create([
        'is_superadmin' => 0,
        'is_active' => 1,
        'current_team_id' => $this->teamId,
    ]);

    DB::table('team_user')->insert([
        'team_id' => $this->teamId,
        'user_id' => $user->id,
        'role' => $role,
    ]);

    $this->actingAs($user);

    // pith_production:view is granted to every role by default (reader included).
    expect(Gate::allows('pith_production.view'))->toBe($expected);
})->with([
    ['reader', true],
    ['staff', true],
    ['supervisor', true],
    ['admin', true],
    ['owner', true],
]);

it('falls through to null (undefined) for abilities outside the module catalog', function () {
    $staff = User::factory()->create([
        'is_superadmin' => 0,
        'is_active' => 1,
        'current_team_id' => $this->teamId,
    ]);

    DB::table('team_user')->insert([
        'team_id' => $this->teamId,
        'user_id' => $staff->id,
        'role' => 'staff',
    ]);

    $this->actingAs($staff);

    // Not a real module — Gate::before must return null so this resolves as
    // "ability not defined" (false) rather than crashing or short-circuiting.
    expect(Gate::allows('not_a_real_module.create'))->toBeFalse();

    // A real gate name that happens to contain a dot-free hyphen is untouched.
    expect(Gate::has('manage-records'))->toBeTrue();
});

it('lets a superadmin through every module.action gate regardless of the matrix', function () {
    $superadmin = User::factory()->create([
        'is_superadmin' => 1,
        'is_active' => 1,
        'current_team_id' => $this->teamId,
    ]);

    seedRolePermissions($this->teamId, 'owner', []);

    $this->actingAs($superadmin);

    expect(Gate::allows('fibre_bales.delete'))->toBeTrue()
        ->and(Gate::allows('pith_production.create'))->toBeTrue();
});
