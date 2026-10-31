<?php

namespace App\Support\Pulse;

use App\Models\Pulse\PulseTeamRole;
use Illuminate\Support\Facades\Cache;

class TeamRolePermissions
{
    private const CACHE_TTL_SECONDS = 3600;

    /** @var array<int, list<array<string, mixed>>> */
    private static array $requestCache = [];

    public static function seedDefaultsForTeam(int $teamId): void
    {
        $ctx = TeamModuleContext::fromTeamId($teamId);
        $roles = config('pulse_role_permissions.roles', []);

        foreach ($roles as $key => $meta) {
            $permissions = self::buildDefaultCapabilityListForRole($teamId, $key, $ctx);

            $role = PulseTeamRole::query()
                ->where('team_id', $teamId)
                ->where('key', $key)
                ->first();

            if ($role) {
                $role->fill([
                    'name' => $meta['label'],
                    'description' => $meta['description'] ?? null,
                    'sort_order' => $meta['sort'] ?? 0,
                    'permissions' => $permissions,
                ])->save();
            } else {
                (new PulseTeamRole)->forceFill([
                    'team_id' => $teamId,
                    'key' => $key,
                    'name' => $meta['label'],
                    'description' => $meta['description'] ?? null,
                    'sort_order' => $meta['sort'] ?? 0,
                    'permissions' => $permissions,
                ])->save();
            }
        }

        self::forgetCache($teamId);
    }

    public static function syncOnModuleChange(int $teamId): void
    {
        $ctx = TeamModuleContext::fromTeamId($teamId);

        PulseTeamRole::where('team_id', $teamId)->each(function (PulseTeamRole $role) use ($teamId, $ctx) {
            $sanitized = self::sanitizePermissions($teamId, $role->permissions ?? [], $role->key, $ctx);
            $role->update(['permissions' => $sanitized]);
        });

        self::forgetCache($teamId);
    }

    /**
     * Section keys allowed for a role. null = all sections (Owner).
     *
     * @return list<string>|null
     */
    public static function sectionKeysForRole(string $roleKey): ?array
    {
        $caps = config('pulse_role_permissions.role_section_caps', []);

        if (! array_key_exists($roleKey, $caps)) {
            return [];
        }

        return $caps[$roleKey];
    }

    /**
     * Module keys allowed for a role. null = all modules (Owner).
     *
     * @return list<string>|null
     */
    public static function allowedModulesForRole(string $roleKey): ?array
    {
        $sectionKeys = self::sectionKeysForRole($roleKey);
        if ($sectionKeys === null) {
            return null;
        }

        $sections = config('pulse_role_permissions.sections', []);
        $modules = [];

        foreach ($sectionKeys as $sectionKey) {
            foreach ($sections[$sectionKey]['modules'] ?? [] as $moduleKey) {
                $modules[] = $moduleKey;
            }
        }

        return array_values(array_unique($modules));
    }

    public static function isModuleAllowedForRole(string $roleKey, string $module): bool
    {
        $allowed = self::allowedModulesForRole($roleKey);

        return $allowed === null || in_array($module, $allowed, true);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function catalogForTeam(int $teamId, ?string $roleKey = null): array
    {
        $ctx = TeamModuleContext::fromTeamId($teamId);
        $sections = config('pulse_role_permissions.sections', []);
        $modules = config('pulse_role_permissions.modules', []);
        $allowedSections = $roleKey !== null ? self::sectionKeysForRole($roleKey) : null;
        $result = [];

        foreach ($sections as $sectionKey => $section) {
            if (is_array($allowedSections) && ! in_array($sectionKey, $allowedSections, true)) {
                continue;
            }

            $visibleModules = [];
            foreach ($section['modules'] as $moduleKey) {
                if (! isset($modules[$moduleKey])) {
                    continue;
                }
                // Reader: Attendance view only — hide Manage Staff from the matrix.
                if ($roleKey === 'reader' && $moduleKey === 'manage_staff') {
                    continue;
                }
                $def = $modules[$moduleKey];
                if ($ctx->matchesVisibleWhen($def['visible_when'] ?? [['always']])) {
                    $visibleModules[$moduleKey] = $def;
                }
            }
            if ($visibleModules !== []) {
                $result[$sectionKey] = [
                    'label' => $section['label'],
                    'modules' => $visibleModules,
                ];
            }
        }

        $index = 1;
        foreach ($result as $sectionKey => $section) {
            $result[$sectionKey]['label'] = $index.'. '.$section['label'];
            $index++;
        }

        return $result;
    }

    /**
     * @return list<array{id:int,team_id:int,key:string,name:string,description:?string,sort_order:int,permissions:list<string>}>
     */
    public static function rolesForTeam(int $teamId): array
    {
        return array_values(self::cachedRoleMap($teamId));
    }

    /**
     * @return array{id:int,team_id:int,key:string,name:string,description:?string,sort_order:int,permissions:list<string>}|null
     */
    public static function roleForTeam(int $teamId, string $roleKey): ?array
    {
        return self::cachedRoleMap($teamId)[$roleKey] ?? null;
    }

    public static function allows(int $teamId, string $roleKey, string $module, string $action): bool
    {
        if (! self::isModuleAllowedForRole($roleKey, $module)) {
            return false;
        }

        $ctx = TeamModuleContext::fromTeamId($teamId);

        if (! $ctx->isModuleVisible($module)) {
            return false;
        }

        $role = self::roleForTeam($teamId, $roleKey);
        if (! $role) {
            return false;
        }

        return in_array(self::capabilityKey($module, $action), $role['permissions'] ?? [], true);
    }

    public static function allowsAny(int $teamId, string $roleKey, array $checks): bool
    {
        foreach ($checks as [$module, $action]) {
            if (self::allows($teamId, $roleKey, $module, $action)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, array<string, bool>>|list<string>  $permissions
     * @return list<string>
     */
    public static function sanitizePermissions(
        int $teamId,
        array $permissions,
        string $roleKey,
        ?TeamModuleContext $ctx = null
    ): array {
        $permissions = self::normalizeStoredPermissions($permissions, $roleKey);
        $matrix = self::expandStoredPermissions($teamId, $permissions, $roleKey, $ctx);
        $ctx ??= TeamModuleContext::fromTeamId($teamId);
        $catalog = self::catalogForTeam($teamId, $roleKey);
        $defaults = self::buildDefaultPermissionsForRole($roleKey, $ctx);
        $sanitized = [];

        foreach ($catalog as $section) {
            foreach ($section['modules'] as $moduleKey => $def) {
                $moduleDefaults = $defaults[$moduleKey] ?? [];
                $submitted = $matrix[$moduleKey] ?? [];
                $merged = [];

                $allActions = self::moduleActions($def);

                foreach ($allActions as $action) {
                    if (array_key_exists($action, $submitted)) {
                        $merged[$action] = (bool) $submitted[$action];
                    } elseif (array_key_exists($action, $moduleDefaults)) {
                        $merged[$action] = (bool) $moduleDefaults[$action];
                    } else {
                        $merged[$action] = false;
                    }
                }

                self::syncColumnCompanions($merged, $def);
                self::enforceViewDependency($merged, $def, $roleKey);
                self::syncColumnCompanions($merged, $def);
                self::applyLocks($merged, $def, $roleKey);
                self::enforceViewDependency($merged, $def, $roleKey);
                self::syncColumnCompanions($merged, $def);
                foreach ($merged as $action => $allowed) {
                    if ($allowed) {
                        $sanitized[] = self::capabilityKey($moduleKey, $action);
                    }
                }
            }
        }

        $sanitized = self::applyPithInventoryParentRules($sanitized);

        $sanitized = array_values(array_unique($sanitized));
        sort($sanitized);

        return $sanitized;
    }

    /**
     * @param  list<string>  $permissions
     * @return array<string, array<string, bool>>
     */
    public static function editorPermissionsForRole(
        int $teamId,
        array $permissions,
        string $roleKey,
        ?TeamModuleContext $ctx = null
    ): array {
        $permissions = self::normalizeStoredPermissions($permissions, $roleKey);
        $ctx ??= TeamModuleContext::fromTeamId($teamId);
        $catalog = self::catalogForTeam($teamId, $roleKey);
        $matrix = [];

        foreach ($catalog as $section) {
            foreach ($section['modules'] as $moduleKey => $def) {
                $modulePermissions = [];
                foreach (self::moduleActions($def) as $action) {
                    $modulePermissions[$action] = in_array(self::capabilityKey($moduleKey, $action), $permissions, true);
                }

                self::syncColumnCompanions($modulePermissions, $def);
                self::enforceViewDependency($modulePermissions, $def, $roleKey);
                self::syncColumnCompanions($modulePermissions, $def);
                self::applyLocks($modulePermissions, $def, $roleKey);
                self::enforceViewDependency($modulePermissions, $def, $roleKey);
                self::syncColumnCompanions($modulePermissions, $def);
                $matrix[$moduleKey] = $modulePermissions;
            }
        }

        return self::applyPithInventoryParentRulesToMatrix($matrix, $roleKey);
    }

    /**
     * @return 'checked'|'unchecked'
     */
    public static function rowFullState(array $moduleDef, array $modulePermissions, string $roleKey = ''): string
    {
        $actions = self::unlockableActions($moduleDef, $roleKey);

        if ($actions === []) {
            return 'unchecked';
        }

        foreach ($actions as $action) {
            if (! ($modulePermissions[$action] ?? false)) {
                return 'unchecked';
            }
        }

        return 'checked';
    }

    /**
     * Column + Others actions that are not lock-forced off for this role.
     *
     * @return list<string>
     */
    public static function unlockableActions(array $moduleDef, string $roleKey): array
    {
        $columns = $moduleDef['columns'] ?? [];
        $others = array_keys($moduleDef['others'] ?? []);
        $actions = [];

        foreach ($columns as $action) {
            if (! self::isActionLocked($moduleDef, $roleKey, $action, 'column')) {
                $actions[] = $action;
            }
        }
        foreach ($others as $action) {
            if (! self::isActionLocked($moduleDef, $roleKey, $action, 'others')) {
                $actions[] = $action;
            }
        }

        return $actions;
    }

    public static function isActionLocked(array $moduleDef, string $roleKey, string $action, string $type = 'column'): bool
    {
        $locks = $moduleDef['locks'][$roleKey] ?? [];
        $key = $type === 'others' ? "others.{$action}" : $action;

        return array_key_exists($key, $locks) && $locks[$key] === false;
    }

    /**
     * Whether an action is lock-forced ON (true) for this role.
     */
    public static function isActionLockedOn(array $moduleDef, string $roleKey, string $action): bool
    {
        $locks = $moduleDef['locks'][$roleKey] ?? [];
        $others = array_keys($moduleDef['others'] ?? []);
        $key = in_array($action, $others, true) ? "others.{$action}" : $action;

        return array_key_exists($key, $locks) && $locks[$key] === true;
    }

    /**
     * View may never be absent when any write/Others/companion is granted.
     * Clearing view clears unlockable non-view actions; locked-ON writes keep view on.
     *
     * $viewCleared says the caller has just turned view off on purpose, rather than handing
     * over a stored state to be normalised. Without it the two are indistinguishable — both
     * arrive as view off with something else still on — and the invariant below reads the
     * deliberate act as the state to repair, switching view straight back on before the
     * clearing can run. A module carrying any non-view action, `pith_inventory` and its
     * Reports among them, then had a view toggle that did nothing at all.
     *
     * @param  array<string, bool>  $permissions
     */
    public static function enforceViewDependency(array &$permissions, array $moduleDef, string $roleKey, bool $viewCleared = false): void
    {
        $columns = $moduleDef['columns'] ?? [];
        if (! in_array('view', $columns, true)) {
            return;
        }

        $nonView = array_values(array_filter(
            self::moduleActions($moduleDef),
            fn (string $action) => $action !== 'view'
        ));

        $hasNonView = false;
        foreach ($nonView as $action) {
            if ($permissions[$action] ?? false) {
                $hasNonView = true;
                break;
            }
        }

        if ($hasNonView && ! $viewCleared) {
            $permissions['view'] = true;
        }

        if (! ($permissions['view'] ?? false)) {
            $others = array_keys($moduleDef['others'] ?? []);
            foreach ($nonView as $action) {
                $type = in_array($action, $others, true) ? 'others' : 'column';
                if (self::isActionLockedOn($moduleDef, $roleKey, $action)) {
                    continue;
                }
                if (self::isActionLocked($moduleDef, $roleKey, $action, $type)) {
                    // Lock-forced off — already false; skip.
                    continue;
                }
                // Companions are not separately locked; clear unless parent column locked on.
                $permissions[$action] = false;
            }

            // Locked-ON non-view forces view back on.
            foreach ($nonView as $action) {
                if (self::isActionLockedOn($moduleDef, $roleKey, $action) || ($permissions[$action] ?? false)) {
                    $permissions['view'] = true;
                    break;
                }
            }
        }
    }

    /**
     * When parent pith_inventory:view is off, clear child inventory modules in the editor matrix.
     *
     * @param  array<string, array<string, bool>>  $matrix
     * @return array<string, array<string, bool>>
     */
    public static function applyPithInventoryParentRulesToMatrix(array $matrix, string $roleKey): array
    {
        $parentOn = (bool) ($matrix['pith_inventory']['view'] ?? false);
        if ($parentOn) {
            return $matrix;
        }

        foreach (self::pithInventoryChildModules() as $childKey) {
            if (! isset($matrix[$childKey])) {
                continue;
            }
            $def = config("pulse_role_permissions.modules.{$childKey}", []);
            foreach (array_keys($matrix[$childKey]) as $action) {
                if (self::isActionLockedOn($def, $roleKey, $action)) {
                    continue;
                }
                $matrix[$childKey][$action] = false;
            }
        }

        return $matrix;
    }

    /**
     * Cross-module pith inventory parent + close-stock rules on a flat capability list.
     *
     * @param  list<string>  $sanitized
     * @return list<string>
     */
    public static function applyPithInventoryParentRules(array $sanitized): array
    {
        $hasParentView = in_array('pith_inventory:view', $sanitized, true);

        if (! $hasParentView) {
            return array_values(array_filter(
                $sanitized,
                fn (string $capability) => ! self::isPithInventoryChildCapability($capability)
            ));
        }

        // Close Location Stock is meaningless without any pith inventory
        // inward OR outward capability when the parent section is enabled.
        $hasInward = collect($sanitized)->contains(
            fn (string $capability) => str_starts_with($capability, 'pith_inventory_inward:')
        );
        $hasOutward = collect($sanitized)->contains(
            fn (string $capability) => str_starts_with($capability, 'pith_inventory_outward:')
        );
        if (! $hasInward && ! $hasOutward) {
            return array_values(array_filter(
                $sanitized,
                fn (string $capability) => ! str_starts_with($capability, 'close_location_stock:')
            ));
        }

        return $sanitized;
    }

    /**
     * Clear child modules when parent view is toggled off (Livewire/editor).
     *
     * @param  array<string, array<string, bool>>  $permissions
     */
    public static function enforcePithInventoryParentChild(array &$permissions, string $roleKey): void
    {
        $permissions = self::applyPithInventoryParentRulesToMatrix($permissions, $roleKey);

        $parentOn = (bool) ($permissions['pith_inventory']['view'] ?? false);
        if ($parentOn) {
            return;
        }

        // Ensure parent key exists when children were cleared.
        if (isset($permissions['pith_inventory'])) {
            $permissions['pith_inventory']['view'] = false;
        }
    }

    /**
     * If any child inventory action is on, enable parent view.
     *
     * @param  array<string, array<string, bool>>  $permissions
     */
    public static function ensurePithInventoryParentFromChildren(array &$permissions): void
    {
        foreach (self::pithInventoryChildModules() as $childKey) {
            foreach ($permissions[$childKey] ?? [] as $allowed) {
                if ($allowed) {
                    if (! isset($permissions['pith_inventory'])) {
                        $permissions['pith_inventory'] = [];
                    }
                    $permissions['pith_inventory']['view'] = true;

                    return;
                }
            }
        }
    }

    /**
     * Inventory modules that require pith_inventory:view when enabled.
     *
     * @return list<string>
     */
    private static function pithInventoryChildModules(): array
    {
        return [
            'pith_inventory_inward',
            'pith_inventory_outward',
            'pith_watering',
            'close_location_stock',
        ];
    }

    private static function isPithInventoryChildCapability(string $capability): bool
    {
        foreach (self::pithInventoryChildModules() as $module) {
            if (str_starts_with($capability, $module.':')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Actions that belong under a column checkbox (not shown as More).
     *
     * @return list<string>
     */
    public static function companionActionsForColumn(array $moduleDef, string $column): array
    {
        $companions = $moduleDef['column_companions'][$column] ?? [];

        return is_array($companions) ? array_values($companions) : [];
    }

    /**
     * @param  array<string, bool>  $permissions
     */
    public static function applyColumnCompanions(array &$permissions, string $moduleKey, string $action, bool $value): void
    {
        $def = config("pulse_role_permissions.modules.{$moduleKey}", []);
        foreach (self::companionActionsForColumn($def, $action) as $companion) {
            $permissions[$companion] = $value;
        }
    }

    /**
     * Keep companion flags aligned with their parent column after load/sanitize.
     *
     * @param  array<string, bool>  $permissions
     */
    public static function syncColumnCompanions(array &$permissions, array $moduleDef): void
    {
        foreach ($moduleDef['column_companions'] ?? [] as $column => $companions) {
            if (! is_array($companions)) {
                continue;
            }
            $parent = (bool) ($permissions[$column] ?? false);
            foreach ($companions as $companion) {
                $permissions[$companion] = $parent;
            }
        }
    }

    /**
     * @return list<string>
     */
    public static function moduleActions(array $moduleDef): array
    {
        $actions = array_merge(
            $moduleDef['columns'] ?? [],
            array_keys($moduleDef['others'] ?? [])
        );

        foreach ($moduleDef['column_companions'] ?? [] as $companions) {
            if (is_array($companions)) {
                $actions = array_merge($actions, $companions);
            }
        }

        return array_values(array_unique($actions));
    }

    /**
     * @return array<string, array<string, bool>>
     */
    public static function buildDefaultPermissionsForRole(string $roleKey, TeamModuleContext $ctx): array
    {
        $modules = config('pulse_role_permissions.modules', []);
        $allowedModules = self::allowedModulesForRole($roleKey);
        $permissions = [];

        foreach ($modules as $moduleKey => $def) {
            if (is_array($allowedModules) && ! in_array($moduleKey, $allowedModules, true)) {
                continue;
            }

            if (! $ctx->matchesVisibleWhen($def['visible_when'] ?? [['always']])) {
                continue;
            }

            $defaults = $def['defaults'][$roleKey] ?? [];
            $merged = [];

            foreach (self::moduleActions($def) as $action) {
                $merged[$action] = (bool) ($defaults[$action] ?? false);
            }

            self::syncColumnCompanions($merged, $def);
            self::enforceViewDependency($merged, $def, $roleKey);
            self::syncColumnCompanions($merged, $def);
            self::applyLocks($merged, $def, $roleKey);
            self::enforceViewDependency($merged, $def, $roleKey);
            self::syncColumnCompanions($merged, $def);
            $permissions[$moduleKey] = $merged;
        }

        return self::applyPithInventoryParentRulesToMatrix($permissions, $roleKey);
    }

    /**
     * @return list<string>
     */
    public static function buildDefaultCapabilityListForRole(int $teamId, string $roleKey, TeamModuleContext $ctx): array
    {
        return self::sanitizePermissions($teamId, self::buildDefaultPermissionsForRole($roleKey, $ctx), $roleKey, $ctx);
    }

    public static function cacheKey(int $teamId): string
    {
        return "pulse:team:{$teamId}:role_permissions";
    }

    public static function forgetCache(int $teamId): void
    {
        unset(self::$requestCache[$teamId]);
        Cache::forget(self::cacheKey($teamId));
    }

    public static function capabilityKey(string $module, string $action): string
    {
        return "{$module}:{$action}";
    }

    /**
     * @return array<string, array{id:int,team_id:int,key:string,name:string,description:?string,sort_order:int,permissions:list<string>}>
     */
    private static function cachedRoleMap(int $teamId): array
    {
        if (isset(self::$requestCache[$teamId])) {
            return self::$requestCache[$teamId];
        }

        /** @var array<string, list<string>> $permissionMap */
        $permissionMap = Cache::remember(self::cacheKey($teamId), self::CACHE_TTL_SECONDS, function () use ($teamId) {
            if (! PulseTeamRole::where('team_id', $teamId)->exists()) {
                self::seedDefaultsForTeam($teamId);
            }

            $ctx = TeamModuleContext::fromTeamId($teamId);
            $map = [];

            PulseTeamRole::where('team_id', $teamId)
                ->orderBy('sort_order')
                ->get(['key', 'permissions'])
                ->each(function (PulseTeamRole $role) use ($teamId, $ctx, &$map) {
                    $map[$role->key] = self::sanitizePermissions(
                        $teamId,
                        $role->permissions ?? [],
                        $role->key,
                        $ctx
                    );
                });

            return $map;
        });

        $roles = [];
        PulseTeamRole::where('team_id', $teamId)
            ->orderBy('sort_order')
            ->get(['id', 'team_id', 'key', 'name', 'description', 'sort_order'])
            ->each(function (PulseTeamRole $role) use ($permissionMap, &$roles) {
                $roles[$role->key] = [
                    'id' => $role->id,
                    'team_id' => $role->team_id,
                    'key' => $role->key,
                    'name' => $role->name,
                    'description' => $role->description,
                    'sort_order' => $role->sort_order,
                    'permissions' => $permissionMap[$role->key] ?? [],
                ];
            });

        self::$requestCache[$teamId] = $roles;

        return $roles;
    }

    /**
     * Normalize legacy capability keys before expand/sanitize.
     *
     * @param  array<string, array<string, bool>>|list<string>  $permissions
     * @return array<string, array<string, bool>>|list<string>
     */
    private static function normalizeStoredPermissions(array $permissions, string $roleKey): array
    {
        $permissions = self::normalizeLegacyNotificationKeys($permissions, $roleKey);
        $permissions = self::normalizeFactoryConfigurationKeys($permissions, $roleKey);
        $permissions = self::stripRetiredCapabilities($permissions);

        return $permissions;
    }

    /**
     * Remap legacy notification_preferences actions to edit for roles that still have section 8.
     *
     * @param  array<string, array<string, bool>>|list<string>  $permissions
     * @return array<string, array<string, bool>>|list<string>
     */
    private static function normalizeLegacyNotificationKeys(array $permissions, string $roleKey): array
    {
        if ($permissions === [] || ! self::isModuleAllowedForRole($roleKey, 'notification_preferences')) {
            return $permissions;
        }

        $first = reset($permissions);
        if (is_array($first)) {
            $module = $permissions['notification_preferences'] ?? [];
            $legacyTrue = (bool) ($module['view'] ?? false)
                || (bool) ($module['toggle_channels'] ?? false)
                || (bool) ($module['save_preferences'] ?? false)
                || (bool) ($module['edit'] ?? false);

            unset($module['view'], $module['toggle_channels'], $module['save_preferences']);
            $module['edit'] = $legacyTrue;
            $permissions['notification_preferences'] = $module;

            return $permissions;
        }

        $legacy = [
            'notification_preferences:view',
            'notification_preferences:toggle_channels',
            'notification_preferences:save_preferences',
        ];
        $hadLegacy = false;
        $normalized = [];

        foreach ($permissions as $capability) {
            if (in_array($capability, $legacy, true)) {
                $hadLegacy = true;

                continue;
            }
            $normalized[] = $capability;
        }

        if ($hadLegacy || in_array('notification_preferences:edit', $permissions, true)) {
            $normalized[] = 'notification_preferences:edit';
        }

        return array_values(array_unique($normalized));
    }

    /**
     * Remap factory_configuration view/edit_team_name/toggle_modules → edit.
     *
     * @param  array<string, array<string, bool>>|list<string>  $permissions
     * @return array<string, array<string, bool>>|list<string>
     */
    private static function normalizeFactoryConfigurationKeys(array $permissions, string $roleKey): array
    {
        if ($permissions === [] || ! self::isModuleAllowedForRole($roleKey, 'factory_configuration')) {
            return $permissions;
        }

        $first = reset($permissions);
        if (is_array($first)) {
            $module = $permissions['factory_configuration'] ?? [];
            $legacyTrue = (bool) ($module['view'] ?? false)
                || (bool) ($module['edit_team_name'] ?? false)
                || (bool) ($module['toggle_modules'] ?? false)
                || (bool) ($module['edit'] ?? false);

            unset($module['view'], $module['edit_team_name'], $module['toggle_modules']);
            $module['edit'] = $legacyTrue;
            $permissions['factory_configuration'] = $module;

            return $permissions;
        }

        $legacy = [
            'factory_configuration:view',
            'factory_configuration:edit_team_name',
            'factory_configuration:toggle_modules',
        ];
        $hadLegacy = false;
        $normalized = [];

        foreach ($permissions as $capability) {
            if (in_array($capability, $legacy, true)) {
                $hadLegacy = true;

                continue;
            }
            $normalized[] = $capability;
        }

        if ($hadLegacy || in_array('factory_configuration:edit', $permissions, true)) {
            $normalized[] = 'factory_configuration:edit';
        }

        return array_values(array_unique($normalized));
    }

    /**
     * Drop retired capabilities that are no longer part of the matrix UI.
     *
     * @param  array<string, array<string, bool>>|list<string>  $permissions
     * @return array<string, array<string, bool>>|list<string>
     */
    private static function stripRetiredCapabilities(array $permissions): array
    {
        if ($permissions === []) {
            return $permissions;
        }

        $first = reset($permissions);
        if (is_array($first)) {
            if (isset($permissions['manage_users'])) {
                unset($permissions['manage_users']['configure_matrix']);
            }

            return $permissions;
        }

        return array_values(array_filter(
            $permissions,
            fn ($capability) => $capability !== 'manage_users:configure_matrix'
        ));
    }

    /**
     * @param  array<string, array<string, bool>>|list<string>  $permissions
     * @return array<string, array<string, bool>>
     */
    private static function expandStoredPermissions(
        int $teamId,
        array $permissions,
        string $roleKey,
        ?TeamModuleContext $ctx = null
    ): array {
        if ($permissions === []) {
            return self::editorPermissionsForRole($teamId, [], $roleKey, $ctx);
        }

        $first = reset($permissions);
        if (is_array($first)) {
            return $permissions;
        }

        return self::editorPermissionsForRole($teamId, $permissions, $roleKey, $ctx);
    }

    /**
     * @param  array<string, bool>  $merged
     */
    private static function applyLocks(array &$merged, array $def, string $roleKey): void
    {
        $locks = $def['locks'][$roleKey] ?? [];
        foreach ($locks as $lockKey => $value) {
            if (str_starts_with($lockKey, 'others.')) {
                $action = substr($lockKey, 7);
                $merged[$action] = (bool) $value;
            } else {
                $merged[$lockKey] = (bool) $value;
            }
        }
    }
}
