<?php

use App\Livewire\ManageRolePermissions;
use App\Livewire\ManageSettings;
use App\Livewire\Pulse\Assets\AssetList;
use App\Livewire\Pulse\ManageShifts;
use App\Livewire\Pulse\Pith\PithProductionList;
use App\Models\Pulse\PulseTeamRole;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Pulse\Support\RbacFixtures;

/**
 * Layer 3 — Nav/button HTML visibility + Livewire action guards.
 *
 * @group rbac
 */
beforeEach(function () {
    $this->teamId = RbacFixtures::team('RBAC UI');
    RbacFixtures::bindTeam($this->teamId);
    $this->users = RbacFixtures::usersForAllRoles($this->teamId);
});

afterEach(function () {
    RbacFixtures::forgetTeam();
});

function rbacPage(string $path): string
{
    return '/'.test()->teamId.'/'.ltrim($path, '/');
}

it('shows setup links to owner', function () {
    fwrite(STDERR, "\n  [TEST STEP] Nav rendering -> Owner sees setup links | Admin setup links hidden");
    // Settings gear / factory setup panel is rendered on gated pages that load the nav.
    $ownerHtml = $this->actingAs($this->users['owner'])
        ->get(rbacPage('pith'))
        ->assertSuccessful()
        ->getContent();

    $setupLinks = [
        'href="'.route('shifts.index', ['team' => $this->teamId]).'"',
        'href="'.route('assetcategories.index', ['team' => $this->teamId]).'"',
        'href="'.route('settings.index', ['team' => $this->teamId]).'"',
    ];

    foreach ($setupLinks as $link) {
        expect($ownerHtml)->toContain($link);
    }

    $adminHtml = $this->actingAs($this->users['admin'])
        ->get(rbacPage('pith'))
        ->assertSuccessful()
        ->getContent();

    foreach ($setupLinks as $link) {
        expect($adminHtml)->not->toContain($link);
    }
})->group('rbac');

it('does not show removed bale and block inventory nav links', function () {
    $adminHtml = $this->actingAs($this->users['admin'])
        ->get(rbacPage('pith'))
        ->assertSuccessful()
        ->getContent();

    expect($adminHtml)->not->toContain('/fibre/inventory')
        ->and($adminHtml)->not->toContain('/pith-blocks/inventory');
})->group('rbac');

it('hides pith inventory nav when parent view is revoked', function () {
    RbacFixtures::revoke($this->teamId, 'admin', ['pith_inventory:view', 'pith_inventory:inventory_report']);

    $adminHtml = $this->actingAs($this->users['admin'])
        ->get(rbacPage('pith'))
        ->assertSuccessful()
        ->getContent();

    expect($adminHtml)->not->toContain('/pith/inventory');
})->group('rbac');

it('allows supervisor, blocks staff', function () {
    $this->actingAs($this->users['supervisor'])
        ->get(rbacPage('users'))
        ->assertSuccessful();

    $this->actingAs($this->users['supervisor'])
        ->get(rbacPage('roles'))
        ->assertSuccessful();

    // Settings gear (nav links) requires view-settings; supervisor has matrix view
    // via manage_users.view but not canManageUsers writes — still can open Roles/Users URLs.
    $this->actingAs($this->users['staff'])
        ->get(rbacPage('users'))
        ->assertForbidden();

    $this->actingAs($this->users['staff'])
        ->get(rbacPage('roles'))
        ->assertForbidden();
})->group('rbac');

it('shows New Production to staff', function () {
    fwrite(STDERR, "\n  [TEST STEP] UI buttons -> Staff sees 'New Production' | Reader does not");
    $this->actingAs($this->users['staff'])
        ->get(rbacPage('pith'))
        ->assertSuccessful()
        ->assertSee('New Production');

    $this->actingAs($this->users['reader'])
        ->get(rbacPage('pith'))
        ->assertSuccessful()
        ->assertDontSee('New Production');
})->group('rbac');

it('blocks staff from deleting production', function () {
    fwrite(STDERR, "\n  [TEST STEP] Staff calling Livewire deleteProduction() -> BLOCKED");
    $this->actingAs($this->users['staff']);
    expect(Gate::allows('pith_production.delete'))->toBeFalse();

    // Unauthorized path returns early without clearing the pending delete id.
    Livewire::test(PithProductionList::class)
        ->set('productionIdToDelete', 1)
        ->call('deleteProduction')
        ->assertSet('productionIdToDelete', 1);
})->group('rbac');

it('blocks asset delete without permission', function () {
    fwrite(STDERR, "\n  [PERMISSION CHANGE] Revoking 'asset_tracking:delete' from Admin role");
    $role = PulseTeamRole::where('team_id', $this->teamId)
        ->where('key', 'admin')
        ->firstOrFail();
    $permissions = array_values(array_filter(
        $role->permissions ?? [],
        fn (string $cap) => ! in_array($cap, [
            'asset_tracking:create',
            'asset_tracking:edit',
            'asset_tracking:delete',
        ], true)
    ));
    if (! in_array('asset_tracking:view', $permissions, true)) {
        $permissions[] = 'asset_tracking:view';
    }
    RbacFixtures::setPermissions($this->teamId, 'admin', $permissions);

    $this->actingAs($this->users['admin']);
    fwrite(STDERR, "\n  [TEST STEP] Admin viewing assets -> ALLOWED | Admin deleting asset -> BLOCKED\n");
    expect(Gate::allows('view-assets'))->toBeTrue()
        ->and(Gate::allows('asset_tracking.delete'))->toBeFalse();

    Livewire::test(AssetList::class)
        ->assertSuccessful()
        ->call('delete', 1)
        ->assertSuccessful();
})->group('rbac');

it('blocks settings access without customization permission', function () {
    $this->actingAs($this->users['admin']);

    Livewire::test(ManageSettings::class)->assertForbidden();
})->group('rbac');

it('blocks shift management without shift permission', function () {
    $this->actingAs($this->users['admin']);

    Livewire::test(ManageShifts::class)->assertForbidden();
})->group('rbac');

it('blocks shift updates without edit access', function () {
    fwrite(STDERR, "\n  [PERMISSION CHANGE] Granting read-only 'shift_settings:view' to Admin (edit off)");
    RbacFixtures::grant($this->teamId, 'admin', ['shift_settings:view']);

    $this->actingAs($this->users['admin']);

    fwrite(STDERR, "\n  [TEST STEP] Admin calling Livewire save() -> BLOCKED with authorization error\n");
    Livewire::test(ManageShifts::class)
        ->call('save')
        ->assertSet('scheduleError', 'You are not authorized to manage shifts.');
})->group('rbac');

it('blocks unauthorized role changes', function () {
    fwrite(STDERR, "\n  [TEST STEP] Admin attempting to save role permissions in Livewire -> 403 Forbidden\n");
    $this->actingAs($this->users['admin']);

    Livewire::test(ManageRolePermissions::class, ['roleKey' => 'staff'])
        ->assertSet('canEdit', false)
        ->call('save')
        ->assertForbidden();
})->group('rbac');

it('allows owner role changes', function () {
    fwrite(STDERR, "\n  [TEST STEP] Owner editing and saving role permissions in Livewire -> SUCCESS\n");
    $this->actingAs($this->users['owner']);

    Livewire::test(ManageRolePermissions::class, ['roleKey' => 'staff'])
        ->assertSet('canEdit', true)
        ->set('roleName', 'Floor Staff')
        ->call('save')
        ->assertHasNoErrors();
})->group('rbac');
