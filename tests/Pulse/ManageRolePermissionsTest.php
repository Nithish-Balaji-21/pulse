<?php

use App\Livewire\ManageRolePermissions;
use App\Models\Pulse\PulseTeamRole;
use App\Models\Team;
use App\Models\User;
use App\Support\Pulse\TeamModuleContext;
use App\Support\Pulse\TeamRolePermissions;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Pulse\Support\TeamFactory;

beforeEach(function () {
    $this->teamId = TeamFactory::create('Role Matrix UI Factory '.uniqid());
    app()->instance('current.team', Team::find($this->teamId));

    $this->owner = User::factory()->create([
        'is_superadmin' => 0,
        'is_active' => 1,
        'current_team_id' => $this->teamId,
    ]);
    DB::table('team_user')->insert([
        'team_id' => $this->teamId,
        'user_id' => $this->owner->id,
        'role' => 'owner',
    ]);

    $this->admin = User::factory()->create([
        'is_superadmin' => 0,
        'is_active' => 1,
        'current_team_id' => $this->teamId,
    ]);
    DB::table('team_user')->insert([
        'team_id' => $this->teamId,
        'user_id' => $this->admin->id,
        'role' => 'admin',
    ]);
});

afterEach(function () {
    app()->forgetInstance('current.team');
});

it('resets permissions to defaults and bumps permissionsVersion', function () {
    $this->actingAs($this->owner);

    $component = Livewire::test(ManageRolePermissions::class, ['roleKey' => 'staff'])
        ->assertSet('canEdit', true)
        ->assertSet('permissionsVersion', 0);

    $defaults = TeamRolePermissions::buildDefaultPermissionsForRole(
        'staff',
        TeamModuleContext::fromTeamId($this->teamId)
    );

    $original = (bool) $component->get('permissions.pith_production.create');
    $component->call('setPermission', 'pith_production', 'create', ! $original)
        ->assertSet('permissions.pith_production.create', ! $original);

    $component->call('resetToDefaults')
        ->assertSet('permissionsVersion', 1)
        ->assertSet('permissions', $defaults);
});

it('rejects empty role name on save', function () {
    $this->actingAs($this->owner);

    Livewire::test(ManageRolePermissions::class, ['roleKey' => 'staff'])
        ->set('roleName', '')
        ->call('save')
        ->assertHasErrors(['roleName' => 'required']);
});

it('rejects duplicate role name on save', function () {
    $this->actingAs($this->owner);

    $adminName = PulseTeamRole::where('team_id', $this->teamId)->where('key', 'admin')->value('name');

    Livewire::test(ManageRolePermissions::class, ['roleKey' => 'staff'])
        ->set('roleName', $adminName)
        ->call('save')
        ->assertHasErrors(['roleName' => 'unique']);
});

it('saves sanitized permissions and increments permissionsVersion', function () {
    $this->actingAs($this->owner);

    Livewire::test(ManageRolePermissions::class, ['roleKey' => 'staff'])
        ->set('roleName', 'Floor Staff')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('permissionsVersion', 1)
        ->assertSet('roleName', 'Floor Staff');

    expect(PulseTeamRole::where('team_id', $this->teamId)->where('key', 'staff')->value('name'))
        ->toBe('Floor Staff');
});

it('toggleFull selects and deselects all unlockable actions for a module', function () {
    $this->actingAs($this->owner);

    $component = Livewire::test(ManageRolePermissions::class, ['roleKey' => 'staff']);

    $component->call('toggleFull', 'pith_production', true)
        ->assertSet('permissions.pith_production.view', true)
        ->assertSet('permissions.pith_production.create', true)
        ->assertSet('permissions.pith_production.edit', true)
        ->assertSet('permissions.pith_production.delete', true);

    $component->call('toggleFull', 'pith_production', false)
        ->assertSet('permissions.pith_production.view', false)
        ->assertSet('permissions.pith_production.create', false)
        ->assertSet('permissions.pith_production.edit', false)
        ->assertSet('permissions.pith_production.delete', false);
});

it('persists permission toggles through save and reload', function () {
    $this->actingAs($this->owner);

    Livewire::test(ManageRolePermissions::class, ['roleKey' => 'staff'])
        ->call('setPermission', 'pith_production', 'create', false)
        ->call('save')
        ->assertHasNoErrors();

    TeamRolePermissions::forgetCache($this->teamId);

    $caps = TeamRolePermissions::roleForTeam($this->teamId, 'staff')['permissions'];
    expect($caps)->toContain('pith_production:view')
        ->and($caps)->not->toContain('pith_production:create');

    Livewire::test(ManageRolePermissions::class, ['roleKey' => 'staff'])
        ->assertSet('permissions.pith_production.create', false);
});

it('forbids non-owner save and reset', function () {
    $this->actingAs($this->admin);

    Livewire::test(ManageRolePermissions::class, ['roleKey' => 'staff'])
        ->assertSet('canEdit', false)
        ->call('save')
        ->assertForbidden();
});

it('forbids non-owner resetToDefaults', function () {
    $this->actingAs($this->admin);

    Livewire::test(ManageRolePermissions::class, ['roleKey' => 'staff'])
        ->call('resetToDefaults')
        ->assertForbidden();
});

it('forbids non-owner setPermission', function () {
    $this->actingAs($this->admin);

    $component = Livewire::test(ManageRolePermissions::class, ['roleKey' => 'staff']);
    $before = $component->get('permissions');

    $component->call('setPermission', 'pith_production', 'view', false);

    expect($component->get('permissions'))->toBe($before);
});

it('locks teamId against client tampering', function () {
    $this->actingAs($this->owner);

    Livewire::test(ManageRolePermissions::class, ['roleKey' => 'staff'])
        ->set('teamId', 999999);
})->throws(CannotUpdateLockedPropertyException::class);

it('allows admin to view role matrix read-only', function () {
    $this->actingAs($this->admin);

    Livewire::test(ManageRolePermissions::class, ['roleKey' => 'staff'])
        ->assertSet('canEdit', false)
        ->assertSee('Read-only view')
        ->assertDontSee('Reset to defaults');
});

it('auto-enables view when a write permission is toggled on', function () {
    $this->actingAs($this->owner);

    Livewire::test(ManageRolePermissions::class, ['roleKey' => 'staff'])
        ->call('setPermission', 'pith_production', 'view', false)
        ->call('setPermission', 'pith_production', 'create', false)
        ->call('setPermission', 'pith_production', 'create', true)
        ->assertSet('permissions.pith_production.view', true)
        ->assertSet('permissions.pith_production.create', true);
});

it('clears child pith inventory permissions when parent view is turned off', function () {
    $this->actingAs($this->owner);

    Livewire::test(ManageRolePermissions::class, ['roleKey' => 'supervisor'])
        ->assertSet('permissions.pith_inventory.view', true)
        ->assertSet('permissions.pith_inventory_inward.view', true)
        ->call('setPermission', 'pith_inventory', 'view', false)
        ->assertSet('permissions.pith_inventory.view', false)
        ->assertSet('permissions.pith_inventory_inward.view', false)
        ->assertSet('permissions.pith_inventory_outward.view', false)
        ->assertSet('permissions.close_location_stock.close', false);
});

it('enables parent pith inventory view when a child permission is enabled', function () {
    $this->actingAs($this->owner);

    Livewire::test(ManageRolePermissions::class, ['roleKey' => 'supervisor'])
        ->call('setPermission', 'pith_inventory', 'view', false)
        ->assertSet('permissions.pith_inventory_inward.view', false)
        ->call('setPermission', 'pith_inventory_inward', 'view', true)
        ->assertSet('permissions.pith_inventory.view', true)
        ->assertSet('permissions.pith_inventory_inward.view', true);
});

it('authorizes team update for owner and denies admin without factory_configuration edit', function () {
    $team = Team::find($this->teamId);

    expect($this->owner->can('update', $team))->toBeTrue();
    expect($this->admin->can('update', $team))->toBeFalse();
});
