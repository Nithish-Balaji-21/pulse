<?php

/**
 * Catalog / sanitize edge cases for the role matrix editor.
 *
 * Production-ready default matrix, Gate aliases, and HTTP/UI coverage live in
 * tests/Pulse/Rbac (run: php artisan test tests/Pulse/Rbac --group=rbac).
 */

use App\Models\Pulse\PulseTeamRole;
use App\Models\Team;
use App\Models\User;
use App\Support\Pulse\TeamModuleContext;
use App\Support\Pulse\TeamRolePermissions;
use Illuminate\Support\Facades\Cache;
use Tests\Pulse\Support\TeamFactory;

beforeEach(function () {
    $this->teamId = TeamFactory::create('Role Matrix Factory '.uniqid());
});

it('filters catalogue by pith submodules', function () {
    DB::table('team_pulse')
        ->where('team_id', $this->teamId)
        ->update([
            'modules' => json_encode([
                'pulse' => [
                    'is_active' => true,
                    'modules' => [
                        'pith' => ['is_active' => true, 'submodules' => ['blocks' => false, 'bales' => true]],
                        'fibre' => ['is_active' => false, 'submodules' => ['weight' => false]],
                        'growbags' => ['is_active' => false],
                        'husks_stock' => ['is_active' => true],
                        'attendance' => ['is_active' => true],
                    ],
                ],
            ]),
        ]);

    TeamRolePermissions::syncOnModuleChange($this->teamId);
    $catalog = TeamRolePermissions::catalogForTeam($this->teamId);

    $productionModules = $catalog['production']['modules'] ?? [];
    expect($productionModules)->toHaveKeys(['pith_production', 'fibre_bales']);
    expect($productionModules)->not->toHaveKey('pith_blocks');

    $inventoryModules = $catalog['inventory']['modules'] ?? [];
    expect($inventoryModules)->toHaveKey('husk_inventory')
        ->and($inventoryModules)->not->toHaveKey('bale_inventory')
        ->and($inventoryModules)->not->toHaveKey('block_inventory');
});

it('hides attendance when module disabled', function () {
    DB::table('team_pulse')
        ->where('team_id', $this->teamId)
        ->update([
            'modules' => json_encode([
                'pulse' => [
                    'is_active' => true,
                    'modules' => [
                        'pith' => ['is_active' => true, 'submodules' => ['blocks' => true, 'bales' => true]],
                        'attendance' => ['is_active' => false],
                    ],
                ],
            ]),
        ]);

    TeamRolePermissions::syncOnModuleChange($this->teamId);
    $catalog = TeamRolePermissions::catalogForTeam($this->teamId);

    $staffModules = $catalog['attendance_staff']['modules'] ?? [];
    expect($staffModules)->not->toHaveKey('attendance');
    expect($staffModules)->toHaveKey('manage_staff');
});

it('strips disabled module keys on sanitize', function () {
    $ctx = TeamModuleContext::fromTeamId($this->teamId);
    $permissions = TeamRolePermissions::buildDefaultPermissionsForRole('owner', $ctx);
    expect($permissions)->toHaveKey('pith_blocks');

    DB::table('team_pulse')
        ->where('team_id', $this->teamId)
        ->update([
            'modules' => json_encode([
                'pulse' => [
                    'is_active' => true,
                    'modules' => [
                        'pith' => ['is_active' => true, 'submodules' => ['blocks' => false, 'bales' => true]],
                        'husks_stock' => ['is_active' => true],
                        'attendance' => ['is_active' => true],
                    ],
                ],
            ]),
        ]);

    $sanitized = TeamRolePermissions::sanitizePermissions($this->teamId, $permissions, 'owner');
    expect($sanitized)->each->toBeString();
    expect($sanitized)->not->toContain('pith_blocks:view');
});

it('expands flat capabilities back to the matrix editor shape', function () {
    $permissions = [
        'pith_production:view',
        'pith_production:create',
        'manage_users:edit',
    ];

    $editorState = TeamRolePermissions::editorPermissionsForRole($this->teamId, $permissions, 'owner');

    expect($editorState['pith_production']['view'])->toBeTrue()
        ->and($editorState['pith_production']['create'])->toBeTrue()
        ->and($editorState['pith_production']['delete'])->toBeFalse()
        ->and($editorState['manage_users']['edit'])->toBeTrue()
        ->and($editorState['manage_users'])->not->toHaveKey('configure_matrix');
});

it('keeps factory setup off for admin and on for owner by default', function () {
    $ctx = TeamModuleContext::fromTeamId($this->teamId);

    $adminDefaults = TeamRolePermissions::buildDefaultPermissionsForRole('admin', $ctx);
    $ownerDefaults = TeamRolePermissions::buildDefaultPermissionsForRole('owner', $ctx);

    expect($adminDefaults['pith_production']['delete'])->toBeTrue()
        ->and($adminDefaults['fibre_bales']['delete'])->toBeTrue()
        ->and($adminDefaults['pith_blocks']['delete'])->toBeTrue()
        ->and($adminDefaults['pith_inventory']['view'])->toBeTrue()
        ->and($adminDefaults['pith_inventory_inward']['delete'])->toBeTrue()
        ->and($adminDefaults['pith_inventory_outward']['delete'])->toBeTrue()
        ->and($adminDefaults['pith_location_groups']['delete'])->toBeTrue()
        ->and($adminDefaults['pith_locations']['delete'])->toBeTrue()
        ->and($adminDefaults['manage_staff']['delete'])->toBeTrue()
        ->and($adminDefaults['asset_tracking']['delete'])->toBeTrue()
        ->and($adminDefaults['husk_inventory']['view'])->toBeTrue()
        ->and($adminDefaults['husk_inventory']['update'])->toBeTrue()
        ->and((bool) ($adminDefaults['shift_settings']['view'] ?? false))->toBeFalse()
        ->and((bool) ($adminDefaults['shift_settings']['create'] ?? false))->toBeFalse()
        ->and((bool) ($adminDefaults['asset_categories']['edit'] ?? false))->toBeFalse()
        ->and((bool) ($adminDefaults['factory_customizations']['edit'] ?? false))->toBeFalse()
        ->and($ownerDefaults['shift_settings']['create'])->toBeTrue()
        ->and($ownerDefaults['asset_categories']['edit'])->toBeTrue()
        ->and($ownerDefaults['factory_customizations']['edit'])->toBeTrue()
        ->and($adminDefaults)->not->toHaveKey('factory_configuration')
        ->and($adminDefaults)->not->toHaveKey('color_settings')
        ->and($ownerDefaults)->not->toHaveKey('color_settings')
        ->and($adminDefaults['notification_preferences']['edit'])->toBeTrue()
        ->and($ownerDefaults['notification_preferences']['edit'])->toBeTrue()
        ->and($ownerDefaults['factory_configuration']['edit'])->toBeTrue()
        ->and($adminDefaults)->not->toHaveKey('geofence_locations')
        ->and($ownerDefaults)->not->toHaveKey('geofence_locations');
});

it('shows geofence locations to owner when the add-on is on', function () {
    DB::table('team_pulse')
        ->where('team_id', $this->teamId)
        ->update([
            'modules' => json_encode([
                'pulse' => [
                    'is_active' => true,
                    'modules' => [
                        'pith' => ['is_active' => true, 'submodules' => ['blocks' => true, 'bales' => true]],
                        'geofencing' => ['is_active' => true, 'max_boundaries' => 5],
                    ],
                ],
            ]),
        ]);

    $ctx = TeamModuleContext::fromTeamId($this->teamId);
    $adminDefaults = TeamRolePermissions::buildDefaultPermissionsForRole('admin', $ctx);
    $ownerDefaults = TeamRolePermissions::buildDefaultPermissionsForRole('owner', $ctx);

    expect((bool) ($adminDefaults['geofence_locations']['create'] ?? false))->toBeFalse()
        ->and($ownerDefaults['geofence_locations']['view'])->toBeTrue()
        ->and($ownerDefaults['geofence_locations']['create'])->toBeTrue()
        ->and($ownerDefaults['geofence_locations']['delete'])->toBeTrue();
});

it('gives reader attendance view only by default', function () {
    $ctx = TeamModuleContext::fromTeamId($this->teamId);
    $readerDefaults = TeamRolePermissions::buildDefaultPermissionsForRole('reader', $ctx);

    expect($readerDefaults['attendance']['view'])->toBeTrue()
        ->and($readerDefaults['husk_inventory']['view'])->toBeTrue()
        ->and((bool) ($readerDefaults['husk_inventory']['update'] ?? false))->toBeFalse()
        ->and((bool) ($readerDefaults['attendance']['create'] ?? false))->toBeFalse()
        ->and((bool) ($readerDefaults['attendance']['edit'] ?? false))->toBeFalse()
        ->and((bool) ($readerDefaults['manage_staff']['view'] ?? false))->toBeFalse();

    $catalog = TeamRolePermissions::catalogForTeam($this->teamId, 'reader');
    expect($catalog['attendance_staff']['modules'])->toHaveKey('attendance')
        ->and($catalog['attendance_staff']['modules'])->not->toHaveKey('manage_staff');

    $caps = TeamRolePermissions::buildDefaultCapabilityListForRole($this->teamId, 'reader', $ctx);
    expect($caps)->toContain('attendance:view')
        ->and($caps)->not->toContain('attendance:create')
        ->and($caps)->not->toContain('attendance:edit');
});

it('computes row full state without indeterminate', function () {
    $def = config('pulse_role_permissions.modules.pith_production');
    expect(TeamRolePermissions::rowFullState($def, ['view' => true, 'create' => false], 'staff'))
        ->toBe('unchecked');
    expect(TeamRolePermissions::rowFullState($def, ['view' => true, 'create' => true, 'edit' => true, 'delete' => true], 'staff'))
        ->toBe('checked');
});

it('row full state ignores lock-forced-off actions', function () {
    $def = config('pulse_role_permissions.modules.pith_production');
    $def['locks'] = [
        'staff' => ['delete' => false],
    ];

    expect(TeamRolePermissions::rowFullState(
        $def,
        ['view' => true, 'create' => true, 'edit' => true, 'delete' => false],
        'staff'
    ))->toBe('checked');
});

it('auto-enables view when a write permission is granted during sanitize', function () {
    $sanitized = TeamRolePermissions::sanitizePermissions($this->teamId, [
        'pith_production:create',
    ], 'staff');

    expect($sanitized)->toContain('pith_production:view')
        ->and($sanitized)->toContain('pith_production:create')
        ->and($sanitized)->toContain('pith_production:view_device_records');
});

it('clears write and others when view is off during sanitize', function () {
    $sanitized = TeamRolePermissions::sanitizePermissions($this->teamId, [
        'attendance:create',
        'attendance:salary_report',
    ], 'supervisor');

    // create forces view on
    expect($sanitized)->toContain('attendance:view')
        ->and($sanitized)->toContain('attendance:create')
        ->and($sanitized)->toContain('attendance:salary_report');

    $withWrites = TeamRolePermissions::sanitizePermissions($this->teamId, [
        'attendance' => [
            'view' => false,
            'create' => true,
            'edit' => true,
            'salary_report' => true,
        ],
    ], 'supervisor');

    // Nested false view with writes → view forced back on by dependency
    expect($withWrites)->toContain('attendance:view')
        ->and($withWrites)->toContain('attendance:create');

    $cleared = TeamRolePermissions::sanitizePermissions($this->teamId, [
        'attendance' => [
            'view' => false,
            'create' => false,
            'edit' => false,
            'salary_report' => false,
        ],
    ], 'supervisor');

    expect($cleared)->not->toContain('attendance:view')
        ->and($cleared)->not->toContain('attendance:create')
        ->and($cleared)->not->toContain('attendance:salary_report');
});

it('strips pith inventory children when parent view is absent', function () {
    $sanitized = TeamRolePermissions::sanitizePermissions($this->teamId, [
        'pith_inventory_inward:view',
        'pith_inventory_outward:create',
        'close_location_stock:close',
    ], 'supervisor');

    expect($sanitized)->not->toContain('pith_inventory:view')
        ->and($sanitized)->not->toContain('pith_inventory_inward:view')
        ->and($sanitized)->not->toContain('pith_inventory_outward:create')
        ->and($sanitized)->not->toContain('close_location_stock:close');
});

it('strips close_location_stock when both inward and outward have zero permissions', function () {
    $sanitized = TeamRolePermissions::sanitizePermissions($this->teamId, [
        'pith_inventory:view',
        'pith_production:view',
        'close_location_stock:close',
    ], 'supervisor');

    expect($sanitized)->toContain('pith_inventory:view')
        ->and($sanitized)->toContain('pith_production:view')
        ->and($sanitized)->not->toContain('close_location_stock:close')
        ->and($sanitized)->not->toContain('pith_inventory_inward:view')
        ->and($sanitized)->not->toContain('pith_inventory_outward:view');
});

it('keeps close_location_stock when only inward is off', function () {
    $sanitized = TeamRolePermissions::sanitizePermissions($this->teamId, [
        'pith_inventory:view',
        'pith_inventory_outward:view',
        'close_location_stock:close',
    ], 'supervisor');

    expect($sanitized)->toContain('pith_inventory:view')
        ->and($sanitized)->toContain('pith_inventory_outward:view')
        ->and($sanitized)->toContain('close_location_stock:close')
        ->and($sanitized)->not->toContain('pith_inventory_inward:view');
});

it('keeps close_location_stock when only outward is off', function () {
    $sanitized = TeamRolePermissions::sanitizePermissions($this->teamId, [
        'pith_inventory:view',
        'pith_inventory_inward:view',
        'close_location_stock:close',
    ], 'supervisor');

    expect($sanitized)->toContain('pith_inventory:view')
        ->and($sanitized)->toContain('pith_inventory_inward:view')
        ->and($sanitized)->toContain('close_location_stock:close')
        ->and($sanitized)->not->toContain('pith_inventory_outward:view');
});

it('folds pith device records into view and assign into edit', function () {
    $def = config('pulse_role_permissions.modules.pith_production');
    expect($def['others'])->toBe([])
        ->and($def['column_companions']['view'])->toContain('view_device_records')
        ->and($def['column_companions']['edit'])->toContain('assign_to_inventory');

    $ctx = TeamModuleContext::fromTeamId($this->teamId);
    $staff = TeamRolePermissions::buildDefaultPermissionsForRole('staff', $ctx);
    expect($staff['pith_production']['view'])->toBeTrue()
        ->and($staff['pith_production']['view_device_records'])->toBeTrue()
        ->and($staff['pith_production']['edit'])->toBeFalse()
        ->and($staff['pith_production']['assign_to_inventory'])->toBeFalse();

    $supervisor = TeamRolePermissions::buildDefaultPermissionsForRole('supervisor', $ctx);
    expect($supervisor['pith_production']['edit'])->toBeTrue()
        ->and($supervisor['pith_production']['assign_to_inventory'])->toBeTrue();

    $perms = ['view' => true, 'create' => false, 'edit' => false, 'delete' => false];
    TeamRolePermissions::applyColumnCompanions($perms, 'pith_production', 'view', true);
    expect($perms['view_device_records'])->toBeTrue();

    TeamRolePermissions::applyColumnCompanions($perms, 'pith_production', 'edit', true);
    expect($perms['assign_to_inventory'])->toBeTrue();
});

it('prefills description from config when db description is null', function () {
    PulseTeamRole::where('team_id', $this->teamId)->where('key', 'admin')->update([
        'description' => null,
    ]);
    TeamRolePermissions::forgetCache($this->teamId);

    $role = TeamRolePermissions::roleForTeam($this->teamId, 'admin');
    $fallback = config('pulse_role_permissions.roles.admin.description');

    expect($role['description'])->toBeNull()
        ->and($fallback)->not->toBeEmpty();
});

it('caps staff catalogue to sections 1-4 only', function () {
    $catalog = TeamRolePermissions::catalogForTeam($this->teamId, 'staff');

    expect(array_keys($catalog))->toEqual([
        'production',
        'inventory',
        'pith_setup',
        'attendance_staff',
    ]);
});

it('caps reader catalogue to sections 1-4 including attendance', function () {
    $catalog = TeamRolePermissions::catalogForTeam($this->teamId, 'reader');

    expect(array_keys($catalog))->toEqual([
        'production',
        'inventory',
        'pith_setup',
        'attendance_staff',
    ]);
});

it('caps supervisor catalogue to sections 1-5 only', function () {
    $catalog = TeamRolePermissions::catalogForTeam($this->teamId, 'supervisor');

    expect(array_keys($catalog))->toEqual([
        'production',
        'inventory',
        'pith_setup',
        'attendance_staff',
        'user_management',
    ]);
});

it('gives admin sections without factory setup or device configuration', function () {
    $catalog = TeamRolePermissions::catalogForTeam($this->teamId, 'admin');

    expect($catalog)->toHaveKeys([
        'production',
        'inventory',
        'pith_setup',
        'attendance_staff',
        'user_management',
        'factory_customization',
        'notifications',
        'assets',
    ]);
    expect($catalog)->not->toHaveKey('factory_setup')
        ->and($catalog)->not->toHaveKey('device_configuration');
});

it('numbers visible catalogue sections consecutively', function () {
    $catalog = TeamRolePermissions::catalogForTeam($this->teamId, 'staff');
    $labels = array_values(array_map(fn ($s) => $s['label'], $catalog));

    expect($labels[0])->toStartWith('1. ')
        ->and($labels[1])->toStartWith('2. ')
        ->and($labels[2])->toStartWith('3. ')
        ->and($labels[3])->toStartWith('4. ');
});

it('renumbers fibre factory sections without pith gaps', function () {
    DB::table('team_pulse')
        ->where('team_id', $this->teamId)
        ->update([
            'modules' => json_encode([
                'pulse' => [
                    'is_active' => true,
                    'modules' => [
                        'pith' => ['is_active' => false, 'submodules' => ['blocks' => false, 'bales' => false]],
                        'fibre' => ['is_active' => true, 'submodules' => ['weight' => true]],
                        'growbags' => ['is_active' => false],
                        'husks_stock' => ['is_active' => false],
                        'attendance' => ['is_active' => true],
                    ],
                ],
            ]),
        ]);

    $catalog = TeamRolePermissions::catalogForTeam($this->teamId, 'staff');
    $labels = array_values(array_map(fn ($s) => $s['label'], $catalog));

    expect($labels[0])->toBe('1. Attendance & Staff')
        ->and($catalog)->toHaveKey('attendance_staff');
});

it('remaps factory configuration legacy keys to edit for owner', function () {
    $sanitized = TeamRolePermissions::sanitizePermissions($this->teamId, [
        'factory_configuration:view',
        'factory_configuration:edit_team_name',
        'factory_configuration:toggle_modules',
    ], 'owner');

    expect($sanitized)->toContain('factory_configuration:edit')
        ->and($sanitized)->not->toContain('factory_configuration:view')
        ->and($sanitized)->not->toContain('factory_configuration:edit_team_name')
        ->and($sanitized)->not->toContain('manage_users:configure_matrix');
});

it('never stores beyond-cap keys for staff even when submitted true', function () {
    $polluted = [
        'pith_production:view',
        'factory_configuration:view',
        'iot_devices:view',
        'manage_users:view',
        'notification_preferences:view',
        'asset_tracking:view',
    ];

    $sanitized = TeamRolePermissions::sanitizePermissions($this->teamId, $polluted, 'staff');

    expect($sanitized)->not->toContain('factory_configuration:view')
        ->and($sanitized)->not->toContain('iot_devices:view')
        ->and($sanitized)->not->toContain('manage_users:view')
        ->and($sanitized)->not->toContain('notification_preferences:view')
        ->and($sanitized)->not->toContain('notification_preferences:edit')
        ->and($sanitized)->not->toContain('asset_tracking:view');

    foreach ($sanitized as $capability) {
        [$module] = explode(':', $capability, 2);
        expect(TeamRolePermissions::isModuleAllowedForRole('staff', $module))->toBeTrue();
    }
});

it('seeds staff without out-of-cap capabilities', function () {
    $staff = collect(TeamRolePermissions::rolesForTeam($this->teamId))->firstWhere('key', 'staff');

    expect($staff['permissions'])->not->toContain('factory_configuration:view')
        ->and($staff['permissions'])->not->toContain('iot_devices:view')
        ->and($staff['permissions'])->not->toContain('manage_users:view');
});

it('remaps legacy notification keys to edit for owner', function () {
    $sanitized = TeamRolePermissions::sanitizePermissions($this->teamId, [
        'notification_preferences:view',
        'notification_preferences:toggle_channels',
        'notification_preferences:save_preferences',
        'shift_settings:view',
    ], 'owner');

    expect($sanitized)->toContain('notification_preferences:edit')
        ->and($sanitized)->not->toContain('notification_preferences:view')
        ->and($sanitized)->not->toContain('notification_preferences:toggle_channels');
});

it('does not mass-assign role key on rename', function () {
    $role = PulseTeamRole::where('team_id', $this->teamId)->where('key', 'staff')->firstOrFail();
    $originalKey = $role->key;

    $role->update([
        'name' => 'Floor Worker',
        'key' => 'hacker',
        'description' => 'Renamed',
    ]);

    $role->refresh();

    expect($role->key)->toBe($originalKey)
        ->and($role->name)->toBe('Floor Worker');
});

it('hides fibre bales and iot devices for fibre-only factories', function () {
    DB::table('team_pulse')
        ->where('team_id', $this->teamId)
        ->update([
            'modules' => json_encode([
                'pulse' => [
                    'is_active' => true,
                    'modules' => [
                        'pith' => ['is_active' => false, 'submodules' => ['blocks' => false, 'bales' => false]],
                        'fibre' => ['is_active' => true, 'submodules' => ['weight' => true]],
                        'growbags' => ['is_active' => false],
                        'attendance' => ['is_active' => true],
                    ],
                ],
            ]),
        ]);

    TeamRolePermissions::syncOnModuleChange($this->teamId);
    $catalog = TeamRolePermissions::catalogForTeam($this->teamId, 'owner');
    $allModules = collect($catalog)->flatMap(fn ($s) => array_keys($s['modules'] ?? []))->all();

    expect($allModules)->not->toContain('fibre_bales')
        ->and($allModules)->not->toContain('iot_devices')
        ->and($allModules)->not->toContain('fibre_bale_machines');
});

it('shows iot devices for pith factories', function () {
    DB::table('team_pulse')
        ->where('team_id', $this->teamId)
        ->update([
            'modules' => json_encode([
                'pulse' => [
                    'is_active' => true,
                    'modules' => [
                        'pith' => ['is_active' => true, 'submodules' => ['blocks' => true, 'bales' => true]],
                        'fibre' => ['is_active' => false, 'submodules' => ['weight' => false]],
                        'attendance' => ['is_active' => true],
                    ],
                ],
            ]),
        ]);

    TeamRolePermissions::syncOnModuleChange($this->teamId);
    $catalog = TeamRolePermissions::catalogForTeam($this->teamId, 'owner');

    expect($catalog['device_configuration']['modules'] ?? [])->toHaveKey('iot_devices');
});

it('caches role permissions and forgets on write', function () {
    TeamRolePermissions::forgetCache($this->teamId);
    Cache::forget(TeamRolePermissions::cacheKey($this->teamId));

    $first = TeamRolePermissions::roleForTeam($this->teamId, 'staff');
    expect(Cache::has(TeamRolePermissions::cacheKey($this->teamId)))->toBeTrue();

    $cached = Cache::get(TeamRolePermissions::cacheKey($this->teamId));
    expect($cached)->toHaveKey('staff')
        ->and($cached['staff'])->toBeArray()
        ->and($cached['staff'])->toBe($first['permissions']);

    PulseTeamRole::where('team_id', $this->teamId)->where('key', 'staff')->update([
        'name' => 'Staff Renamed',
    ]);

    // Stale request/cache until forget
    expect(TeamRolePermissions::roleForTeam($this->teamId, 'staff')['name'])->toBe($first['name']);

    TeamRolePermissions::forgetCache($this->teamId);
    expect(TeamRolePermissions::roleForTeam($this->teamId, 'staff')['name'])->toBe('Staff Renamed');
});

it('preserves manual role permission removals without re-enabling defaults at runtime', function () {
    PulseTeamRole::where('team_id', $this->teamId)->where('key', 'admin')->update([
        'permissions' => [
            'pith_production:view',
            'pith_production:create',
            'pith_production:edit',
            'husk_inventory:view',
            'asset_tracking:view',
            'asset_tracking:create',
            'asset_tracking:edit',
        ],
    ]);

    PulseTeamRole::where('team_id', $this->teamId)->where('key', 'reader')->update([
        'permissions' => [],
    ]);

    TeamRolePermissions::forgetCache($this->teamId);

    $admin = TeamRolePermissions::roleForTeam($this->teamId, 'admin');
    $reader = TeamRolePermissions::roleForTeam($this->teamId, 'reader');

    expect($admin['permissions'])->not->toContain('pith_production:delete')
        ->and($admin['permissions'])->not->toContain('asset_tracking:delete')
        ->and($admin['permissions'])->not->toContain('husk_inventory:update')
        ->and($reader['permissions'])->not->toContain('husk_inventory:view')
        ->and($reader['permissions'])->not->toContain('husk_inventory:update');

    $adminStored = PulseTeamRole::where('team_id', $this->teamId)->where('key', 'admin')->value('permissions');
    $readerStored = PulseTeamRole::where('team_id', $this->teamId)->where('key', 'reader')->value('permissions');

    expect($adminStored)->not->toContain('pith_production:delete')
        ->and($adminStored)->not->toContain('asset_tracking:delete')
        ->and($adminStored)->not->toContain('husk_inventory:update')
        ->and($readerStored)->not->toContain('husk_inventory:view')
        ->and($readerStored)->not->toContain('husk_inventory:update');
});

it('resolves User::allows per module for seeded roles', function () {
    $owner = User::factory()->create([
        'is_superadmin' => 0,
        'is_active' => 1,
        'current_team_id' => $this->teamId,
    ]);
    $reader = User::factory()->create([
        'is_superadmin' => 0,
        'is_active' => 1,
        'current_team_id' => $this->teamId,
    ]);
    $superadmin = User::factory()->create([
        'is_superadmin' => 1,
        'is_active' => 1,
        'current_team_id' => $this->teamId,
    ]);

    DB::table('team_user')->insert([
        ['team_id' => $this->teamId, 'user_id' => $owner->id, 'role' => 'owner'],
        ['team_id' => $this->teamId, 'user_id' => $reader->id, 'role' => 'reader'],
        ['team_id' => $this->teamId, 'user_id' => $superadmin->id, 'role' => 'reader'],
    ]);

    app()->instance('current.team', Team::find($this->teamId));
    TeamRolePermissions::forgetCache($this->teamId);

    expect($owner->allows('pith_production', 'view'))->toBeTrue()
        ->and($owner->allows('pith_production', 'create'))->toBeTrue()
        ->and($owner->allows('pith_production', 'delete'))->toBeTrue()
        ->and($reader->allows('pith_production', 'view'))->toBeTrue()
        ->and($reader->allows('pith_production', 'create'))->toBeFalse()
        ->and($reader->allows('pith_production', 'edit'))->toBeFalse()
        ->and($reader->allows('pith_production', 'delete'))->toBeFalse()
        ->and($reader->allows('fibre_bales', 'create'))->toBeFalse()
        ->and($superadmin->allows('pith_production', 'delete'))->toBeTrue()
        ->and($superadmin->allows('fibre_bales', 'create'))->toBeTrue();

    app()->forgetInstance('current.team');
});
