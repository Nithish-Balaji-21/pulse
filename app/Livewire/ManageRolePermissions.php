<?php

namespace App\Livewire;

use App\Models\Pulse\PulseTeamRole;
use App\Support\Pulse\TeamModuleContext;
use App\Support\Pulse\TeamRolePermissions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ManageRolePermissions extends Component
{
    #[Locked]
    public int $teamId;

    #[Locked]
    public ?string $roleKey = null;

    /** @var array<string, mixed> */
    public array $permissions = [];

    /** @var array<string, array<string, mixed>> */
    public array $catalog = [];

    /** @var list<array<string, mixed>> */
    public array $rolesList = [];

    public string $roleName = '';

    public string $roleDescription = '';

    #[Locked]
    public bool $canEdit = false;

    /** Bumped so Alpine remounts after server-driven permission changes. */
    public int $permissionsVersion = 0;

    public ?string $othersModalModule = null;

    /** @var array<string, bool> */
    public array $othersModalValues = [];

    public function mount(?string $roleKey = null): void
    {
        $team = app()->has('current.team') ? app('current.team') : Auth::user()->currentTeam;
        $this->teamId = $team->id;
        $this->roleKey = $roleKey;
        $this->canEdit = (bool) (Auth::user()?->canEditRoleMatrix());

        if ($roleKey) {
            $this->loadRole($roleKey);
        } else {
            $this->loadRolesList();
        }
    }

    public function loadRolesList(): void
    {
        $roles = TeamRolePermissions::rolesForTeam($this->teamId);
        $counts = DB::table('team_user')
            ->where('team_id', $this->teamId)
            ->select('role', DB::raw('count(*) as total'))
            ->groupBy('role')
            ->pluck('total', 'role');

        $this->rolesList = collect($roles)->map(fn (array $role) => [
            'key' => $role['key'],
            'name' => $role['name'],
            'description' => $role['description'] ?? config("pulse_role_permissions.roles.{$role['key']}.description"),
            'member_count' => (int) ($counts[$role['key']] ?? 0),
        ])->values()->all();
    }

    public function loadRole(string $roleKey): void
    {
        $role = TeamRolePermissions::roleForTeam($this->teamId, $roleKey);
        abort_unless($role, 404);

        $this->roleKey = $roleKey;
        $this->roleName = $role['name'];
        $this->roleDescription = $role['description']
            ?? (string) (config("pulse_role_permissions.roles.{$roleKey}.description") ?? '');
        $this->catalog = TeamRolePermissions::catalogForTeam($this->teamId, $roleKey);
        $this->permissions = TeamRolePermissions::editorPermissionsForRole(
            $this->teamId,
            $role['permissions'] ?? [],
            $roleKey
        );
    }

    public function openOthersModal(string $moduleKey): void
    {
        if (! $this->assertCanEdit()) {
            return;
        }

        $this->othersModalModule = $moduleKey;
        $def = config("pulse_role_permissions.modules.{$moduleKey}");
        $this->othersModalValues = [];

        foreach (array_keys($def['others'] ?? []) as $action) {
            if (TeamRolePermissions::isActionLocked($def, $this->roleKey, $action, 'others')) {
                continue;
            }
            $this->othersModalValues[$action] = (bool) ($this->permissions[$moduleKey][$action] ?? false);
        }
    }

    public function closeOthersModal(): void
    {
        $this->othersModalModule = null;
        $this->othersModalValues = [];
    }

    public function applyOthersModal(): void
    {
        if (! $this->assertCanEdit() || ! $this->othersModalModule) {
            return;
        }

        $moduleKey = $this->othersModalModule;
        $def = config("pulse_role_permissions.modules.{$moduleKey}", []);
        $current = $this->permissions[$moduleKey] ?? [];

        foreach ($this->othersModalValues as $action => $value) {
            $current[$action] = (bool) $value;
        }

        TeamRolePermissions::enforceViewDependency($current, $def, $this->roleKey);
        TeamRolePermissions::syncColumnCompanions($current, $def);
        $this->permissions[$moduleKey] = $current;
        TeamRolePermissions::ensurePithInventoryParentFromChildren($this->permissions);
        TeamRolePermissions::enforcePithInventoryParentChild($this->permissions, $this->roleKey);

        $this->closeOthersModal();
        $this->permissionsVersion++;
    }

    public function toggleFull(string $moduleKey, bool $checked): void
    {
        if (! $this->assertCanEdit()) {
            return;
        }

        $def = config("pulse_role_permissions.modules.{$moduleKey}");
        if (! $def) {
            return;
        }

        $current = $this->permissions[$moduleKey] ?? [];

        foreach (TeamRolePermissions::unlockableActions($def, $this->roleKey) as $action) {
            $current[$action] = $checked;
            TeamRolePermissions::applyColumnCompanions($current, $moduleKey, $action, $checked);
        }

        TeamRolePermissions::enforceViewDependency($current, $def, $this->roleKey);
        TeamRolePermissions::syncColumnCompanions($current, $def);
        $this->permissions[$moduleKey] = $current;

        if ($moduleKey === 'pith_inventory' && ! $checked) {
            TeamRolePermissions::enforcePithInventoryParentChild($this->permissions, $this->roleKey);
        } else {
            TeamRolePermissions::ensurePithInventoryParentFromChildren($this->permissions);
        }
    }

    public function setPermission(string $moduleKey, string $action, bool $value): void
    {
        if (! $this->assertCanEdit()) {
            return;
        }

        $def = config("pulse_role_permissions.modules.{$moduleKey}");
        if ($def && TeamRolePermissions::isActionLocked($def, $this->roleKey, $action)) {
            return;
        }

        $current = $this->permissions[$moduleKey] ?? [];
        $current[$action] = $value;
        TeamRolePermissions::applyColumnCompanions($current, $moduleKey, $action, $value);
        if ($def) {
            TeamRolePermissions::enforceViewDependency($current, $def, $this->roleKey, $action === 'view' && ! $value);
            TeamRolePermissions::syncColumnCompanions($current, $def);
        }
        $this->permissions[$moduleKey] = $current;

        if ($moduleKey === 'pith_inventory' && $action === 'view' && ! $value) {
            TeamRolePermissions::enforcePithInventoryParentChild($this->permissions, $this->roleKey);
        } else {
            TeamRolePermissions::ensurePithInventoryParentFromChildren($this->permissions);
        }
    }

    public function save(): void
    {
        abort_unless($this->assertCanEdit() && $this->roleKey, 403);

        $role = PulseTeamRole::where('team_id', $this->teamId)
            ->where('key', $this->roleKey)
            ->firstOrFail();

        $this->validate([
            'roleName' => [
                'required',
                'string',
                'max:100',
                Rule::unique('pulse_team_roles', 'name')
                    ->where(fn ($q) => $q->where('team_id', $this->teamId))
                    ->ignore($role->id),
            ],
            'roleDescription' => ['nullable', 'string', 'max:1000'],
        ]);

        $sanitized = TeamRolePermissions::sanitizePermissions(
            $this->teamId,
            $this->permissions,
            $this->roleKey
        );

        $role->update([
            'name' => $this->roleName,
            'description' => $this->roleDescription ?: null,
            'permissions' => $sanitized,
        ]);

        TeamRolePermissions::forgetCache($this->teamId);
        $this->permissions = TeamRolePermissions::editorPermissionsForRole(
            $this->teamId,
            $sanitized,
            $this->roleKey
        );
        $this->permissionsVersion++;

        session()->flash('success', 'Role permissions saved.');
    }

    public function resetToDefaults(): void
    {
        abort_unless($this->assertCanEdit() && $this->roleKey, 403);

        $ctx = TeamModuleContext::fromTeamId($this->teamId);
        $this->permissions = TeamRolePermissions::buildDefaultPermissionsForRole($this->roleKey, $ctx);
        $this->catalog = TeamRolePermissions::catalogForTeam($this->teamId, $this->roleKey);
        $this->permissionsVersion++;

        session()->flash('status', 'Permissions reset to defaults (not saved yet). Click Save to persist.');
    }

    public function render()
    {
        if ($this->roleKey) {
            return view('livewire.manage-role-permissions-edit')
                ->layout('layouts.app');
        }

        return view('livewire.manage-role-permissions-list')
            ->layout('layouts.app');
    }

    /**
     * Re-check server-side; do not trust the Locked $canEdit snapshot alone
     * if the user's role changed mid-session.
     */
    private function assertCanEdit(): bool
    {
        return (bool) (Auth::user()?->canEditRoleMatrix());
    }
}
