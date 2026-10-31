<?php

use App\Support\Pulse\TeamModuleContext;
use App\Support\Pulse\TeamRolePermissions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Tests\Pulse\Support\RbacFixtures;

/**
 * Layer 1 — Permission engine (defaults, Gate::before, sanitize rules).
 *
 * @group rbac
 */
beforeEach(function () {
    $this->teamId = RbacFixtures::team('RBAC Engine');
    RbacFixtures::bindTeam($this->teamId);
    $this->users = RbacFixtures::usersForAllRoles($this->teamId);
});

afterEach(function () {
    RbacFixtures::forgetTeam();
});

dataset('rbac_roles', RbacFixtures::ROLES);

it('checks default permissions for every role', function (string $role) {
    $user = $this->users[$role];
    $this->actingAs($user);

    $ctx = TeamModuleContext::fromTeamId($this->teamId);
    $expected = TeamRolePermissions::buildDefaultCapabilityListForRole($this->teamId, $role, $ctx);
    $catalog = TeamRolePermissions::catalogForTeam($this->teamId, $role);

    foreach ($catalog as $section) {
        foreach ($section['modules'] as $moduleKey => $def) {
            $actions = array_merge(
                $def['columns'] ?? [],
                array_keys($def['others'] ?? [])
            );
            foreach ($actions as $action) {
                $capability = $moduleKey.':'.$action;
                $gate = $moduleKey.'.'.$action;
                $shouldAllow = in_array($capability, $expected, true);

                expect(Gate::allows($gate))
                    ->toBe($shouldAllow, "{$role} expected Gate::{$gate}=".($shouldAllow ? 'true' : 'false'));
            }
        }
    }
})->with('rbac_roles')->group('rbac');

it('checks default permissions', function (string $role, string $module, string $action, bool $expected) {
    $status = $expected ? 'ALLOWED' : 'BLOCKED';
    fwrite(STDERR, "\n  [TEST STEP] {$role} -> {$module}.{$action} -> {$status}");
    $this->actingAs($this->users[$role]);

    expect(Gate::allows("{$module}.{$action}"))->toBe($expected)
        ->and($this->users[$role]->allows($module, $action))->toBe($expected);
})->with([
    // Production
    ['reader', 'pith_production', 'view', true],
    ['reader', 'pith_production', 'create', false],
    ['staff', 'pith_production', 'create', true],
    ['staff', 'pith_production', 'delete', false],
    ['supervisor', 'pith_production', 'edit', true],
    ['supervisor', 'fibre_bales', 'delete', false],
    ['admin', 'fibre_bales', 'delete', true],
    ['owner', 'pith_blocks', 'delete', true],
    ['reader', 'growbag_production', 'view', true],

    // Inventory
    ['reader', 'pith_inventory', 'view', true],
    ['staff', 'pith_inventory_outward', 'create', true],
    ['staff', 'pith_inventory_outward', 'delete', false],
    ['supervisor', 'close_location_stock', 'close', true],
    ['staff', 'close_location_stock', 'close', false],
    ['reader', 'husk_inventory', 'view', true],
    ['staff', 'husk_inventory', 'update', true],
    ['staff', 'husk_inventory', 'daily_reminder', true],
    ['supervisor', 'husk_inventory', 'daily_reminder', false],
    ['supervisor', 'husk_inventory', 'update', true],

    // Factory customization — Admin off, Owner on
    ['admin', 'factory_customizations', 'view', false],
    ['admin', 'shift_settings', 'view', false],
    ['admin', 'asset_categories', 'view', false],
    ['owner', 'factory_customizations', 'view', true],
    ['owner', 'factory_customizations', 'edit', true],
    ['owner', 'shift_settings', 'delete', true],

    // Users / assets / notifications
    ['supervisor', 'manage_users', 'view', true],
    ['admin', 'manage_users', 'create', false],
    ['owner', 'manage_users', 'create', true],
    ['staff', 'asset_tracking', 'view', false],
    ['admin', 'asset_tracking', 'delete', true],
    ['admin', 'notification_preferences', 'edit', true],
    ['staff', 'notification_preferences', 'edit', false],

    // Device views — Owner only
    ['admin', 'iot_devices', 'view', false],
    ['owner', 'iot_devices', 'view', true],
    ['owner', 'fibre_bale_machines', 'view', true],
])->group('rbac');

it('checks alternate feature names', function (string $role, string $gate, bool $expected) {
    $status = $expected ? 'ALLOWED' : 'BLOCKED';
    fwrite(STDERR, "\n  [TEST STEP] {$role} -> Gate '{$gate}' -> {$status}");
    $this->actingAs($this->users[$role]);

    expect(Gate::allows($gate))->toBe($expected);
})->with([
    ['reader', 'view-pith-production', true],
    ['reader', 'view-pith-inventory', true],
    ['reader', 'view-assets', false],
    ['admin', 'view-assets', true],
    ['admin', 'view-factory-customization', false],
    ['owner', 'view-factory-customization', true],
    ['admin', 'manage-factory-setup', false],
    ['owner', 'manage-factory-setup', true],
    ['staff', 'view-attendance', true],
    ['staff', 'close-location-stock', false],
    ['supervisor', 'close-location-stock', true],
    ['reader', 'view-users', false],
    ['supervisor', 'view-users', true],
    ['owner', 'view-role-matrix', true],
])->group('rbac');

it('allows granted permissions only', function () {
    fwrite(STDERR, "\n  [PERMISSION CHANGE] Setting permissions for Staff to only 'fibre_bales:edit'");
    RbacFixtures::setPermissions($this->teamId, 'staff', ['fibre_bales:edit']);
    $this->actingAs($this->users['staff']);

    fwrite(STDERR, "\n  [TEST STEP] Staff accessing 'fibre_bales.edit' -> ALLOWED | create/delete -> BLOCKED\n");
    expect(Gate::allows('fibre_bales.edit'))->toBeTrue()
        ->and(Gate::allows('fibre_bales.create'))->toBeFalse()
        ->and(Gate::allows('fibre_bales.delete'))->toBeFalse()
        ->and(Gate::allows('supervisor-production'))->toBeTrue()
        ->and(Gate::allows('fibre_bales.delete'))->toBeFalse();
})->group('rbac');

it('blocks unregistered features', function () {
    fwrite(STDERR, "\n  [TEST STEP] Unregistered capability gate 'not_a_real_module.create' -> BLOCKED\n");
    $this->actingAs($this->users['staff']);

    expect(Gate::allows('not_a_real_module.create'))->toBeFalse()
        ->and(Gate::has('manage-records'))->toBeTrue();
})->group('rbac');

it('gives superadmin full access', function () {
    fwrite(STDERR, "\n  [PERMISSION CHANGE] Revoking all permissions from Owner role, but flagging user as superadmin");
    RbacFixtures::setPermissions($this->teamId, 'owner', []);
    $superadmin = RbacFixtures::user($this->teamId, 'owner', true);
    $this->actingAs($superadmin);

    fwrite(STDERR, "\n  [TEST STEP] Superadmin accessing all features -> ALLOWED\n");
    expect(Gate::allows('fibre_bales.delete'))->toBeTrue()
        ->and(Gate::allows('pith_production.create'))->toBeTrue()
        ->and(Gate::allows('factory_customizations.edit'))->toBeTrue();
})->group('rbac');

it('adds view when write is enabled', function () {
    fwrite(STDERR, "\n  [PERMISSION CHANGE] Enabling write permission 'pith_production:create' for Staff");
    $sanitized = TeamRolePermissions::sanitizePermissions($this->teamId, [
        'pith_production:create',
    ], 'staff');

    fwrite(STDERR, "\n  [TEST STEP] Engine auto-grants 'pith_production:view' & device records\n");
    expect($sanitized)->toContain('pith_production:view')
        ->and($sanitized)->toContain('pith_production:create')
        ->and($sanitized)->toContain('pith_production:view_device_records');
})->group('rbac');

it('removes write when view is disabled', function () {
    fwrite(STDERR, "\n  [PERMISSION CHANGE] Turning off 'attendance:view' for Supervisor");
    $cleared = TeamRolePermissions::sanitizePermissions($this->teamId, [
        'attendance' => [
            'view' => false,
            'create' => false,
            'edit' => false,
            'salary_report' => false,
        ],
    ], 'supervisor');

    fwrite(STDERR, "\n  [TEST STEP] Engine auto-strips all attendance write/report permissions\n");
    expect($cleared)->not->toContain('attendance:view')
        ->and($cleared)->not->toContain('attendance:create')
        ->and($cleared)->not->toContain('attendance:salary_report');
})->group('rbac');

it('removes child permissions when parent view is off', function () {
    fwrite(STDERR, "\n  [PERMISSION CHANGE] Revoking parent 'pith_inventory:view' while submitting child permissions for Supervisor");
    $sanitized = TeamRolePermissions::sanitizePermissions($this->teamId, [
        'pith_inventory_inward:view',
        'pith_inventory_outward:create',
        'close_location_stock:close',
    ], 'supervisor');

    fwrite(STDERR, "\n  [TEST STEP] Engine strips all child inventory permissions\n");
    expect($sanitized)->not->toContain('pith_inventory:view')
        ->and($sanitized)->not->toContain('pith_inventory_inward:view')
        ->and($sanitized)->not->toContain('pith_inventory_outward:create')
        ->and($sanitized)->not->toContain('close_location_stock:close');
})->group('rbac');

it('keeps child permissions when parent view is on', function () {
    fwrite(STDERR, "\n  [PERMISSION CHANGE] Enabling parent 'pith_inventory:view' alongside child permissions for Admin");
    $sanitized = TeamRolePermissions::sanitizePermissions($this->teamId, [
        'pith_inventory:view',
        'pith_inventory_inward:create',
        'pith_inventory_outward:delete',
        'close_location_stock:close',
    ], 'admin');

    fwrite(STDERR, "\n  [TEST STEP] Engine preserves all child inventory permissions\n");
    expect($sanitized)->toContain('pith_inventory:view')
        ->and($sanitized)->toContain('pith_inventory_inward:create')
        ->and($sanitized)->toContain('pith_inventory_outward:delete')
        ->and($sanitized)->toContain('close_location_stock:close');
})->group('rbac');

it('blocks unauthorized permission changes', function () {
    fwrite(STDERR, "\n  [PERMISSION CHANGE] Attempting to give Staff high-level admin keys ('manage_users', 'factory_configuration')");
    $sanitized = TeamRolePermissions::sanitizePermissions($this->teamId, [
        'pith_production:view',
        'manage_users:view',
        'factory_configuration:edit',
        'iot_devices:view',
    ], 'staff');

    fwrite(STDERR, "\n  [TEST STEP] Engine rejects and strips unauthorized keys for Staff\n");
    expect($sanitized)->toContain('pith_production:view')
        ->and($sanitized)->not->toContain('manage_users:view')
        ->and($sanitized)->not->toContain('factory_configuration:edit')
        ->and($sanitized)->not->toContain('iot_devices:view');
})->group('rbac');

it('caches and clears permissions', function () {
    $cacheKey = TeamRolePermissions::cacheKey($this->teamId);
    Cache::forget($cacheKey);
    expect(Cache::has($cacheKey))->toBeFalse();

    fwrite(STDERR, "\n  [TEST STEP] Resolving Admin permission -> Caches matrix in memory");
    TeamRolePermissions::allows($this->teamId, 'admin', 'pith_production', 'view');
    expect(Cache::has($cacheKey))->toBeTrue();

    fwrite(STDERR, "\n  [PERMISSION CHANGE] Owner grants 'factory_customizations:view' to Admin");
    RbacFixtures::grant($this->teamId, 'admin', ['factory_customizations:view']);

    fwrite(STDERR, "\n  [TEST STEP] Permission cache is instantly cleared\n");
    expect(Cache::has($cacheKey))->toBeFalse();
})->group('rbac');
