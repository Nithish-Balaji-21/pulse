<?php

use App\Models\Pulse\PulseTeamRole;
use App\Models\Team;
use App\Models\User;
use App\Support\Pulse\TeamRolePermissions;
use Illuminate\Support\Facades\DB;
use Tests\Pulse\Support\TeamFactory;

beforeEach(function () {
    $this->teamId = TeamFactory::create('Pulse View Auth Factory '.uniqid(), false, [
        'modules' => [
            'pith' => ['is_active' => true, 'submodules' => ['blocks' => true, 'bales' => true]],
            'fibre' => ['is_active' => false, 'submodules' => ['weight' => false]],
            'growbags' => ['is_active' => true],
            'husks_stock' => ['is_active' => true],
            'attendance' => ['is_active' => true],
        ],
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

    app()->instance('current.team', Team::find($this->teamId));
    TeamRolePermissions::syncOnModuleChange($this->teamId);
});

afterEach(function () {
    app()->forgetInstance('current.team');
});

it('lets an admin reach matrix-backed production and inventory view pages before permissions are removed', function () {
    $this->actingAs($this->admin)
        ->get("/{$this->teamId}/pith")
        ->assertSuccessful();

    $this->actingAs($this->admin)
        ->get("/{$this->teamId}/pith/inventory")
        ->assertSuccessful();

    $this->actingAs($this->admin)
        ->get("/{$this->teamId}/husk/inventory")
        ->assertSuccessful();

    $this->actingAs($this->admin)
        ->get("/{$this->teamId}/fibre")
        ->assertSuccessful();

    $this->actingAs($this->admin)
        ->get("/{$this->teamId}/pith-blocks")
        ->assertSuccessful();

    $this->actingAs($this->admin)
        ->get("/{$this->teamId}/growbag-production")
        ->assertSuccessful();

    $this->actingAs($this->admin)
        ->get("/{$this->teamId}/assets")
        ->assertSuccessful();
});

it('blocks direct urls after admin view permissions are removed from the role matrix', function () {
    $role = PulseTeamRole::where('team_id', $this->teamId)
        ->where('key', 'admin')
        ->firstOrFail();

    $blockedPrefixes = [
        'pith_production:',
        'fibre_bales:',
        'pith_blocks:',
        'pith_inventory:',
        'pith_inventory_inward:',
        'pith_inventory_outward:',
        'close_location_stock:',
        'husk_inventory:',
        'growbag_production:',
        'asset_tracking:',
    ];

    $role->update([
        'permissions' => array_values(array_filter(
            $role->permissions ?? [],
            fn (string $capability) => ! collect($blockedPrefixes)->contains(
                fn (string $prefix) => str_starts_with($capability, $prefix)
            )
        )),
    ]);

    TeamRolePermissions::forgetCache($this->teamId);

    $this->actingAs($this->admin)
        ->get("/{$this->teamId}/pith")
        ->assertForbidden();

    $this->actingAs($this->admin)
        ->get("/{$this->teamId}/pith/report/999999")
        ->assertForbidden();

    $this->actingAs($this->admin)
        ->get("/{$this->teamId}/pith/inventory")
        ->assertForbidden();

    $this->actingAs($this->admin)
        ->get("/{$this->teamId}/pith/inventory/history")
        ->assertForbidden();

    $this->actingAs($this->admin)
        ->get("/{$this->teamId}/pith/taken/1")
        ->assertForbidden();

    $this->actingAs($this->admin)
        ->get("/{$this->teamId}/husk/inventory")
        ->assertForbidden();

    $this->actingAs($this->admin)
        ->get("/{$this->teamId}/fibre")
        ->assertForbidden();

    $this->actingAs($this->admin)
        ->get("/{$this->teamId}/pith-blocks")
        ->assertForbidden();

    $this->actingAs($this->admin)
        ->get("/{$this->teamId}/growbag-production")
        ->assertForbidden();

    $this->actingAs($this->admin)
        ->get("/{$this->teamId}/assets")
        ->assertForbidden();

    $this->actingAs($this->admin)
        ->get("/{$this->teamId}/assets/view/999999")
        ->assertForbidden();
});

/**
 * Reports sits on the pith_inventory module beside view, and cannot be held without it: a
 * stored state carrying one and not the other is repaired rather than honoured. Revoking the
 * section therefore means revoking the module, which is what the role editor writes when the
 * parent is switched off — stripping view alone leaves the role exactly as it was.
 */
it('blocks pith inventory when the parent section is removed', function () {
    $role = PulseTeamRole::where('team_id', $this->teamId)
        ->where('key', 'admin')
        ->firstOrFail();

    $withoutView = array_values(array_filter(
        $role->permissions ?? [],
        fn (string $capability) => $capability !== 'pith_inventory:view'
    ));

    $role->update(['permissions' => $withoutView]);
    TeamRolePermissions::forgetCache($this->teamId);

    expect($this->admin->fresh()->canViewPithInventory())->toBeTrue();

    $role->update([
        'permissions' => array_values(array_filter(
            $withoutView,
            fn (string $capability) => ! str_starts_with($capability, 'pith_inventory:')
        )),
    ]);
    TeamRolePermissions::forgetCache($this->teamId);

    expect($this->admin->fresh()->canViewPithInventory())->toBeFalse();

    $this->actingAs($this->admin)
        ->get("/{$this->teamId}/pith/inventory")
        ->assertForbidden();
});

it('returns not found for removed finished-goods inventory pages', function () {
    $this->actingAs($this->admin)
        ->get("/{$this->teamId}/fibre/inventory")
        ->assertNotFound();

    $this->actingAs($this->admin)
        ->get("/{$this->teamId}/pith-blocks/inventory")
        ->assertNotFound();
});

it('blocks factory setup pages for admin by default', function () {
    $this->actingAs($this->admin)
        ->get("/{$this->teamId}/settings")
        ->assertForbidden();

    $this->actingAs($this->admin)
        ->get("/{$this->teamId}/shifts")
        ->assertForbidden();

    $this->actingAs($this->admin)
        ->get("/{$this->teamId}/assetcategories")
        ->assertForbidden();
});

it('gates each factory setup page by its own module view capability', function () {
    $role = PulseTeamRole::where('team_id', $this->teamId)
        ->where('key', 'admin')
        ->firstOrFail();

    // Shift Settings alone must not open Customizations or Asset Categories.
    $role->update([
        'permissions' => array_values(array_unique(array_merge(
            $role->permissions ?? [],
            ['shift_settings:view']
        ))),
    ]);
    TeamRolePermissions::forgetCache($this->teamId);

    $this->actingAs($this->admin)
        ->get("/{$this->teamId}/shifts")
        ->assertSuccessful();

    $this->actingAs($this->admin)
        ->get("/{$this->teamId}/settings")
        ->assertForbidden();

    $this->actingAs($this->admin)
        ->get("/{$this->teamId}/assetcategories")
        ->assertForbidden();

    $role->update([
        'permissions' => array_values(array_unique(array_merge(
            $role->permissions ?? [],
            [
                'factory_customizations:view',
                'asset_categories:view',
            ]
        ))),
    ]);
    TeamRolePermissions::forgetCache($this->teamId);

    $this->actingAs($this->admin)
        ->get("/{$this->teamId}/settings")
        ->assertSuccessful();

    $this->actingAs($this->admin)
        ->get("/{$this->teamId}/assetcategories")
        ->assertSuccessful();
});
