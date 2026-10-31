<?php

use App\Models\Pulse\PulseTeamRole;
use App\Models\Team;
use App\Models\User;
use App\Support\Pulse\TeamRolePermissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Tests\Pulse\Support\TeamFactory;

beforeEach(function () {
    $this->teamId = TeamFactory::create('Pith Inventory Parent Gate '.uniqid());

    $this->supervisor = User::factory()->create([
        'is_superadmin' => 0,
        'is_active' => 1,
        'current_team_id' => $this->teamId,
    ]);

    DB::table('team_user')->insert([
        'team_id' => $this->teamId,
        'user_id' => $this->supervisor->id,
        'role' => 'supervisor',
    ]);

    app()->instance('current.team', Team::find($this->teamId));
    TeamRolePermissions::forgetCache($this->teamId);
});

afterEach(function () {
    app()->forgetInstance('current.team');
});

/**
 * Turn the parent Pith Inventory section off for a role, the way the role editor does.
 *
 * The whole module goes, not just `pith_inventory:view`. Reports lives on the same module,
 * and a stored state holding Reports without the view it reads through is one the editor
 * cannot produce — normalisation repairs it by putting view back, so revoking the single
 * capability revokes nothing at all.
 */
function revokePithInventoryParent(PulseTeamRole $role): void
{
    $role->update([
        'permissions' => array_values(array_filter(
            $role->permissions ?? [],
            fn (string $capability) => ! str_starts_with($capability, 'pith_inventory:')
        )),
    ]);
    TeamRolePermissions::forgetCache($role->team_id);
}

it('gates surplus mutations with pith_inventory_inward capabilities', function () {
    $this->actingAs($this->supervisor);

    expect(Gate::allows('pith_inventory_inward.create'))->toBeTrue()
        ->and(Gate::allows('pith_inventory_inward.edit'))->toBeTrue()
        ->and(Gate::allows('pith_inventory_inward.delete'))->toBeFalse();

    expect(Gate::allows('pith_inventory_outward.create'))->toBeTrue();
});

it('requires parent pith inventory view for inventory API reads', function () {
    Sanctum::actingAs($this->supervisor);

    $this->getJson('/api/pith/inventory')
        ->assertSuccessful();

    $role = PulseTeamRole::where('team_id', $this->teamId)
        ->where('key', 'supervisor')
        ->firstOrFail();

    revokePithInventoryParent($role);

    $this->getJson('/api/pith/inventory')
        ->assertForbidden();
});

it('blocks inventory mutations when parent pith inventory view is removed', function () {
    Sanctum::actingAs($this->supervisor);

    $this->postJson('/api/pith/surplus/store', [])
        ->assertStatus(422);

    $role = PulseTeamRole::where('team_id', $this->teamId)
        ->where('key', 'supervisor')
        ->firstOrFail();

    revokePithInventoryParent($role);

    $this->postJson('/api/pith/surplus/store', [])
        ->assertForbidden();

    $this->postJson('/api/pith/taken/store', [])
        ->assertForbidden();

    $this->postJson('/api/pith/closelocationstock/1')
        ->assertForbidden();
});

it('requires parent pith inventory view for close location stock', function () {
    $this->actingAs($this->supervisor);

    expect(Gate::allows('close-location-stock'))->toBeTrue();

    $role = PulseTeamRole::where('team_id', $this->teamId)
        ->where('key', 'supervisor')
        ->firstOrFail();

    revokePithInventoryParent($role);

    expect(Gate::allows('close-location-stock'))->toBeFalse();
});

it('gates production inventory modal data with pith production view', function () {
    Sanctum::actingAs($this->supervisor);

    // Middleware runs before the controller; a missing record yields 404, not 403.
    $this->getJson('/api/pith/inventory/999999')
        ->assertNotFound();

    $role = PulseTeamRole::where('team_id', $this->teamId)
        ->where('key', 'supervisor')
        ->firstOrFail();

    $role->update([
        'permissions' => array_values(array_filter(
            $role->permissions ?? [],
            fn (string $capability) => ! str_starts_with($capability, 'pith_production:')
        )),
    ]);
    TeamRolePermissions::forgetCache($this->teamId);

    $this->getJson('/api/pith/inventory/999999')
        ->assertForbidden();
});
