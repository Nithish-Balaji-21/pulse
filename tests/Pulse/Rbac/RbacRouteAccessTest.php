<?php

use Tests\Pulse\Support\RbacFixtures;

/**
 * Layer 2 — Web route access (can: middleware on web pages).
 *
 * Mobile API RBAC is covered by RbacMobileApiTest.php.
 *
 * @group rbac
 */
beforeEach(function () {
    $this->teamId = RbacFixtures::team('RBAC Routes');
    RbacFixtures::bindTeam($this->teamId);
    $this->users = RbacFixtures::usersForAllRoles($this->teamId);
});

afterEach(function () {
    RbacFixtures::forgetTeam();
});

function rbacUrl(string $path): string
{
    $teamId = test()->teamId;

    return "/{$teamId}/".ltrim($path, '/');
}

it('checks page access', function (string $role, string $path, int $status) {
    fwrite(STDERR, "\n  [TEST STEP] {$role} -> GET /{$path} -> {$status}");
    $this->actingAs($this->users[$role])
        ->get(rbacUrl($path))
        ->assertStatus($status);
})->with([
    // Production / inventory — all roles can view by default
    ['reader', 'pith', 200],
    ['staff', 'pith', 200],
    ['reader', 'pith/inventory', 200],
    ['reader', 'husk/inventory', 200],
    ['reader', 'fibre', 200],
    ['reader', 'pith-blocks', 200],
    ['reader', 'growbag-production', 200],
    ['reader', 'attendance', 200],

    // Inventory reports — supervisor+ only
    ['reader', 'reports/inventory/pith', 403],
    ['staff', 'reports/inventory/pith', 403],
    ['supervisor', 'reports/inventory/pith', 200],
    ['admin', 'reports/inventory/pith', 200],
    ['reader', 'reports/inventory/husk', 403],
    ['staff', 'reports/inventory/husk', 403],
    ['supervisor', 'reports/inventory/husk', 200],

    // Create pages
    ['reader', 'pith/create', 403],
    ['staff', 'pith/create', 200],
    ['reader', 'fibre/create', 403],
    ['staff', 'fibre/create', 403],
    ['supervisor', 'fibre/create', 200],
    ['reader', 'pith-blocks/create', 403],
    ['supervisor', 'pith-blocks/create', 200],
    ['staff', 'attendance/edit/2026-01-01', 403],
    ['supervisor', 'attendance/edit/2026-01-01', 200],

    // Assets — admin+
    ['reader', 'assets', 403],
    ['staff', 'assets', 403],
    ['supervisor', 'assets', 403],
    ['admin', 'assets', 200],
    ['owner', 'assets', 200],

    // Users / roles / staff
    ['reader', 'users', 403],
    ['staff', 'users', 403],
    ['supervisor', 'users', 200],
    ['admin', 'users', 200],
    ['reader', 'roles', 403],
    ['supervisor', 'roles', 200],
    ['reader', 'staffs', 403],
    ['staff', 'staffs', 200],
    ['admin', 'staffs', 200],

    // Pith locations — view for staff+
    ['reader', 'pithlocations', 200],
    ['staff', 'pithlocations', 200],

    // Factory Setup — Owner only by default (Admin off)
    ['reader', 'settings', 403],
    ['staff', 'settings', 403],
    ['supervisor', 'settings', 403],
    ['admin', 'settings', 403],
    ['owner', 'settings', 200],
    ['admin', 'shifts', 403],
    ['owner', 'shifts', 200],
    ['admin', 'assetcategories', 403],
    ['owner', 'assetcategories', 200],

    // Devices — superadmin only
    ['owner', 'devices', 403],
    ['admin', 'devices', 403],

    // Factory configuration device pages — Owner view
    ['admin', 'water-meters', 403],
    ['owner', 'water-meters', 200],
    ['owner', 'water-tanks', 200],
    ['owner', 'growbag-machines', 200],
    ['owner', 'fibrebale-machines', 200],
    ['admin', 'growbag-machines', 403],
])->group('rbac');

it('blocks factory setup pages for admin until owner grants permission', function () {
    $admin = $this->users['admin'];

    fwrite(STDERR, "\n  [TEST STEP] Admin attempting setup pages -> 403 Forbidden");
    $this->actingAs($admin)->get(rbacUrl('settings'))->assertForbidden();
    $this->actingAs($admin)->get(rbacUrl('shifts'))->assertForbidden();
    $this->actingAs($admin)->get(rbacUrl('assetcategories'))->assertForbidden();

    fwrite(STDERR, "\n  [PERMISSION CHANGE] Owner grants 'shift_settings:view' to Admin");
    RbacFixtures::grant($this->teamId, 'admin', ['shift_settings:view']);

    fwrite(STDERR, "\n  [TEST STEP] Admin accessing shifts -> 200 OK | other setup pages -> 403 Forbidden");
    $this->actingAs($admin)->get(rbacUrl('shifts'))->assertSuccessful();
    $this->actingAs($admin)->get(rbacUrl('settings'))->assertForbidden();
    $this->actingAs($admin)->get(rbacUrl('assetcategories'))->assertForbidden();

    fwrite(STDERR, "\n  [PERMISSION CHANGE] Owner grants remaining customization permissions to Admin");
    RbacFixtures::grant($this->teamId, 'admin', [
        'factory_customizations:view',
        'asset_categories:view',
    ]);

    fwrite(STDERR, "\n  [TEST STEP] Admin accessing all setup pages -> 200 OK\n");
    $this->actingAs($admin)->get(rbacUrl('settings'))->assertSuccessful();
    $this->actingAs($admin)->get(rbacUrl('assetcategories'))->assertSuccessful();
})->group('rbac');

it('checks bale and block inventory report access and permission revocation', function () {
    $this->actingAs($this->users['supervisor'])
        ->get(rbacUrl('reports/inventory/bales'))
        ->assertSuccessful();
    $this->actingAs($this->users['supervisor'])
        ->get(rbacUrl('reports/inventory/blocks'))
        ->assertSuccessful();

    $this->actingAs($this->users['reader'])
        ->get(rbacUrl('reports/inventory/bales'))
        ->assertForbidden();
    $this->actingAs($this->users['reader'])
        ->get(rbacUrl('reports/inventory/blocks'))
        ->assertForbidden();

    // Production pages stay available when report-level create/edit is revoked.
    RbacFixtures::revoke($this->teamId, 'supervisor', [
        'fibre_bales:create',
        'fibre_bales:edit',
        'pith_blocks:create',
        'pith_blocks:edit',
    ]);

    $this->actingAs($this->users['supervisor'])
        ->get(rbacUrl('fibre'))
        ->assertSuccessful();
    $this->actingAs($this->users['supervisor'])
        ->get(rbacUrl('pith-blocks'))
        ->assertSuccessful();
    $this->actingAs($this->users['supervisor'])
        ->get(rbacUrl('reports/inventory/bales'))
        ->assertForbidden();
    $this->actingAs($this->users['supervisor'])
        ->get(rbacUrl('reports/inventory/blocks'))
        ->assertForbidden();
})->group('rbac');

it('keeps bale and block inventory reports accessible when only parent pith_inventory view is revoked', function () {
    RbacFixtures::revoke($this->teamId, 'supervisor', [
        'pith_inventory:view',
        'pith_inventory:inventory_report',
    ]);

    $this->actingAs($this->users['supervisor'])
        ->get(rbacUrl('reports/inventory/pith'))
        ->assertForbidden();
    $this->actingAs($this->users['supervisor'])
        ->get(rbacUrl('reports/inventory/bales'))
        ->assertSuccessful();
    $this->actingAs($this->users['supervisor'])
        ->get(rbacUrl('reports/inventory/blocks'))
        ->assertSuccessful();
})->group('rbac');

it('redirects unauthenticated users', function () {
    fwrite(STDERR, "\n  [TEST STEP] Unauthenticated web route access -> 302 Redirect to Login\n");
    $this->get(rbacUrl('pith'))->assertRedirect();
    $this->get(rbacUrl('settings'))->assertRedirect();
})->group('rbac');
