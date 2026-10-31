<?php

namespace App\Models;

use App\Models\Pulse\MiniBaleProduction;
use App\Services\Impersonation;
use App\Support\Pulse\TeamRolePermissions;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Jetstream\HasProfilePhoto;
use Laravel\Jetstream\HasTeams;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use HasProfilePhoto;
    use HasTeams;
    use Notifiable;
    use TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'email_verified_at',
        'mobile',
        'password',
        'current_team_id',
        'is_superadmin',
        'is_active',
        'is_internal',
        'last_login_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'profile_photo_url',
        'current_team_module',
        'current_team_role',
        'current_team_isActive',
        'current_team_license_valid',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * Check if the user is a superadmin.
     */
    public function isSuperadmin(): bool
    {
        return $this->is_superadmin === 1;
    }

    /**
     * Determine if the user is an admin or superadmin, cached in the session.
     */
    public function getIsAdminAttribute(): bool
    {
        return $this->getCurrentTeamRole() === 'admin';
    }

    /**
     * Drop cached role flags for this user (all teams) after role or impersonation changes.
     */
    public function forgetRoleSessionCache(): void
    {
        $prefix = "user_is_admin_{$this->id}_";

        foreach (array_keys(session()->all()) as $key) {
            if (str_starts_with($key, $prefix)) {
                session()->forget($key);
            }
        }
    }

    public function getCurrentTeamModuleAttribute()
    {
        $team = Team::find($this->current_team_id);
        $module = $team?->has_pith_module ? 'pith' : ($team?->has_fibre_module ? 'fibre' : ($team?->has_growbags_module ? 'growbags' : null));

        return $module;
    }

    public function getCurrentTeamRoleAttribute(): ?string
    {
        return $this->getCurrentTeamRole(); // Reuse existing logic
    }

    public function getCurrentTeamIsActiveAttribute()
    {
        $team = Team::find($this->current_team_id);

        return (bool) ($team?->pulse?->is_active ?? false);
    }

    public function getCurrentTeamLicenseValidAttribute()
    {
        $team = Team::find($this->current_team_id);

        return $team?->pulse?->license_valid_till; // returns date or null
    }

    /**
     * This user's role in the team the request is actually running in.
     *
     * Not `current_team_id`: SetActiveTeam is route middleware on the `{team}`
     * group, so it never runs for `POST /livewire/update`, leaving that column
     * pointing at whichever team an explicit switch last wrote. A user who is
     * staff in one factory and reader in another was then authorized against the
     * wrong one — Livewire-rendered buttons appeared that the following full
     * request answered with 403. ResolveActiveTeam binds `current.team` on the
     * whole web group, so it is correct on both kinds of request.
     */
    public function getCurrentTeamRole(): ?string
    {
        $teamId = app()->has('current.team')
            ? app('current.team')->id
            : $this->current_team_id;

        if (! $teamId) {
            return null;
        }

        $teamUser = DB::table('team_user')
            ->where('team_id', $teamId)
            ->where('user_id', $this->id)
            ->first();

        return $teamUser?->role;
    }

    public function canAccessSuperadminSettings(): bool
    {
        return $this->isSuperadmin();
    }

    public function canAccessSettings(): bool
    {
        return $this->isSuperadmin()
            || $this->canManageUsers()
            || $this->canManageStaff()
            || $this->canManagePithLocationOperations()
            || $this->canViewFactoryCustomization()
            || $this->canManageFactoryCustomization()
            || $this->canEditFactoryConfiguration()
            || $this->canViewFactoryConfiguration();
    }

    /**
     * Whether the user may open the Factory Configuration team settings page.
     */
    public function canEditFactoryConfiguration(): bool
    {
        if ($this->isSuperadmin()) {
            return true;
        }

        return $this->teamAllows('factory_configuration', 'edit');
    }

    /**
     * Whether the user may open device-configuration pages (water, machines, IoT).
     */
    public function canViewFactoryConfiguration(): bool
    {
        if ($this->isSuperadmin()) {
            return true;
        }

        return $this->teamAllowsAny([
            ['iot_devices', 'view'],
            ['water_meters', 'view'],
            ['water_tanks', 'view'],
            ['fibre_bale_machines', 'view'],
            ['growbag_machines', 'view'],
        ]);
    }

    public function canManageFactoryConfiguration(): bool
    {
        if ($this->writesWithdrawn()) {
            return false;
        }

        return $this->isSuperadmin();
    }

    /**
     * Whether a read-only impersonation session has withdrawn write capabilities.
     *
     * Applied to the capability methods rather than the blades so a read-only
     * session hides every add/edit/delete control in one place — the alternative
     * is offering a supervisor's buttons and failing at the database, which reads
     * as the app being broken. Enforcement still lives in the guards registered by
     * Impersonation::registerGuards(); this is the UI half of the same rule.
     *
     * Read capabilities (canAccessSettings, canAccessTeam) are deliberately
     * untouched — looking at a screen is the entire point of impersonation.
     */
    private function writesWithdrawn(): bool
    {
        return Impersonation::isReadOnly();
    }

    private function hasRole(string ...$roles): bool
    {
        return in_array($this->getCurrentTeamRole(), $roles, true);
    }

    /**
     * The supervisor tier and above: supervisor, admin, owner.
     *
     * Staff stop below this line — they record production but do not close a
     * location, correct stock, or mark attendance.
     */
    private function hasSupervisorTier(): bool
    {
        if ($this->writesWithdrawn()) {
            return false;
        }

        return $this->isSuperadmin() || $this->hasRole('supervisor', 'admin', 'owner');
    }

    private function currentTeamHasPithModule(): bool
    {
        if (app()->has('current.team')) {
            return (bool) (app('current.team')?->has_pith_module);
        }

        return (bool) ($this->currentTeam?->has_pith_module);
    }

    /**
     * An admin adds members below their own level; an owner adds admins too.
     * Which roles each may hand out is answered by assignableRoles().
     */
    public function canAddUsers(): bool
    {
        return $this->assignableRoles() !== [];
    }

    public function canUpdateUsers(): bool
    {
        if ($this->writesWithdrawn()) {
            return false;
        }

        return $this->canManageUsers();
    }

    private function currentTeamId(): ?int
    {
        if (app()->has('current.team')) {
            return app('current.team')?->id;
        }

        return $this->currentTeam?->id;
    }

    /**
     * Modules the active team cannot hold at all — withdrawn rather than merely hidden, so the
     * superadmin bypass in allows() does not reopen them.
     *
     * Labour is the only one today: a device-only factory — fibre and/or growbags, no pith — is a
     * production line whose output the device counts, so there is no per-head attendance to record
     * against it. The role matrix says the same thing declaratively via `not.device_only` on the
     * `attendance` module, but that only governs what a role may be granted; this closes the
     * direct-URL route for a superadmin too.
     */
    private function moduleWithdrawnForActiveTeam(string $module): bool
    {
        if ($module !== 'attendance') {
            return false;
        }

        $teamId = $this->currentTeamId();

        return $teamId ? (bool) Team::find($teamId)?->is_device_only_factory : false;
    }

    /**
     * Actions that decide who gets interrupted rather than who is permitted, so the superadmin
     * bypass below deliberately skips them.
     *
     * A superadmin holds every right in every factory, but holding a right is not a reason to be
     * handed an operator's daily prompt: `husk_inventory:daily_reminder` names the role that has
     * to record yesterday's husk intake, and a superadmin looking in on a factory is not that
     * person. These resolve through the team role matrix like anyone else's, which also means a
     * superadmin who is not a member of the team holds no role there and so is never prompted.
     *
     * @var list<string>
     */
    private const ADVISORY_ACTIONS = [
        'daily_reminder',
    ];

    /**
     * Public per-module matrix check for Blade/Livewire UI.
     * Write actions respect impersonation read-only; superadmin bypasses the matrix except for
     * the advisory actions above.
     */
    public function allows(string $module, string $action): bool
    {
        $writeActions = [
            'create',
            'edit',
            'delete',
            'update',
            'close',
            'assign_to_inventory',
            'salary_report',
            'inventory_report',
            'reset_default',
        ];

        if (in_array($action, $writeActions, true) && $this->writesWithdrawn()) {
            return false;
        }

        if ($this->moduleWithdrawnForActiveTeam($module)) {
            return false;
        }

        if ($this->isSuperadmin() && ! in_array($action, self::ADVISORY_ACTIONS, true)) {
            return true;
        }

        return $this->teamAllows($module, $action);
    }

    private function teamAllows(string $module, string $action): bool
    {
        $teamId = $this->currentTeamId();
        $role = $this->getCurrentTeamRole();

        if (! $teamId || ! $role) {
            return false;
        }

        return TeamRolePermissions::allows($teamId, $role, $module, $action);
    }

    private function teamAllowsAny(array $checks): bool
    {
        $teamId = $this->currentTeamId();
        $role = $this->getCurrentTeamRole();

        if (! $teamId || ! $role) {
            return false;
        }

        return TeamRolePermissions::allowsAny($teamId, $role, $checks);
    }

    /**
     * Public per-module OR check for nav/section visibility (e.g. "does the
     * user hold ANY of these view/create/edit/delete capabilities").
     *
     * Deliberately does not apply writesWithdrawn() the way allows() does for
     * a single write action: this is used to decide whether a nav item or
     * settings section should render at all, and a read-only impersonation
     * session should still see the sections it would normally see — the
     * write buttons within them are what get hidden, via the individual
     * `module.action` gates and capability methods.
     *
     * @param  list<array{0: string, 1: string}>  $checks
     */
    public function allowsAny(array $checks): bool
    {
        if ($this->isSuperadmin()) {
            return true;
        }

        return $this->teamAllowsAny($checks);
    }

    /**
     * Capability list for the user's current team role (mobile / validate-token).
     *
     * Returns module:action strings from pulse_team_roles. Superadmins receive
     * an empty list — callers should treat is_superadmin as allow-all.
     *
     * @return list<string>
     */
    public function currentTeamPermissions(): array
    {
        if ($this->isSuperadmin()) {
            return [];
        }

        $teamId = $this->currentTeamId();
        $role = $this->getCurrentTeamRole();

        if (! $teamId || ! $role) {
            return [];
        }

        $roleRow = TeamRolePermissions::roleForTeam($teamId, $role);

        return array_values($roleRow['permissions'] ?? []);
    }

    /**
     * Check if the user can delete records (owner by default).
     */
    public function canDeleteRecords(): bool
    {
        if ($this->writesWithdrawn()) {
            return false;
        }

        if ($this->isSuperadmin()) {
            return true;
        }

        return $this->teamAllowsAny([
            ['pith_production', 'delete'],
            ['fibre_bales', 'delete'],
            ['pith_blocks', 'delete'],
            ['pith_inventory_inward', 'delete'],
            ['pith_inventory_outward', 'delete'],
            ['pith_watering', 'delete'],
            ['pith_location_groups', 'delete'],
            ['pith_locations', 'delete'],
            ['manage_staff', 'delete'],
            ['manage_users', 'delete'],
            ['shift_settings', 'delete'],
            ['asset_categories', 'delete'],
            ['asset_tracking', 'delete'],
        ]);
    }

    /**
     * Check if the user can *only* read (i.e., reader role).
     */
    public function canManageRecords(): bool
    {
        if ($this->writesWithdrawn()) {
            return false;
        }

        if ($this->isSuperadmin()) {
            return true;
        }

        return $this->teamAllowsAny([
            ['pith_production', 'create'],
            ['pith_production', 'edit'],
            ['fibre_bales', 'create'],
            ['fibre_bales', 'edit'],
            ['pith_blocks', 'create'],
            ['pith_blocks', 'edit'],
            ['pith_inventory_inward', 'create'],
            ['pith_inventory_inward', 'edit'],
            ['pith_inventory_outward', 'create'],
            ['pith_inventory_outward', 'edit'],
            ['pith_watering', 'create'],
            ['pith_watering', 'edit'],
            ['asset_tracking', 'create'],
            ['asset_tracking', 'edit'],
        ]);
    }

    /**
     * Check if the user can access Husks Stock (supervisor and above, pith factories).
     */
    public function canManageHuskStock(): bool
    {
        if ($this->writesWithdrawn()) {
            return false;
        }

        if ($this->isSuperadmin()) {
            return true;
        }

        return $this->teamAllows('husk_inventory', 'update');
    }

    /**
     * Whether the user may open the attendance / Labour calendar (view).
     */
    public function canViewAttendance(): bool
    {
        if ($this->moduleWithdrawnForActiveTeam('attendance')) {
            return false;
        }

        if ($this->isSuperadmin()) {
            return true;
        }

        return $this->teamAllows('attendance', 'view');
    }

    public function canViewPithProduction(): bool
    {
        if ($this->isSuperadmin()) {
            return true;
        }

        return $this->teamAllows('pith_production', 'view');
    }

    public function canViewFibreBales(): bool
    {
        if ($this->isSuperadmin()) {
            return true;
        }

        return $this->teamAllows('fibre_bales', 'view');
    }

    /**
     * Fibre Bales inventory reports require view + create + edit (supervisor+ by default).
     */
    public function canAccessFibreBalesInventoryReport(): bool
    {
        return $this->allows('fibre_bales', 'view')
            && $this->allows('fibre_bales', 'create')
            && $this->allows('fibre_bales', 'edit');
    }

    public function canViewPithBlocks(): bool
    {
        if ($this->isSuperadmin()) {
            return true;
        }

        return $this->teamAllows('pith_blocks', 'view');
    }

    /**
     * Pith Blocks inventory reports require view + create + edit (supervisor+ by default).
     */
    public function canAccessPithBlocksInventoryReport(): bool
    {
        return $this->allows('pith_blocks', 'view')
            && $this->allows('pith_blocks', 'create')
            && $this->allows('pith_blocks', 'edit');
    }

    public function canViewPithInventory(): bool
    {
        if ($this->isSuperadmin()) {
            return true;
        }

        return $this->teamAllows('pith_inventory', 'view');
    }

    public function canViewHuskInventory(): bool
    {
        if ($this->isSuperadmin()) {
            return true;
        }

        return $this->teamAllows('husk_inventory', 'view');
    }

    public function canViewGrowbagProduction(): bool
    {
        if ($this->isSuperadmin()) {
            return true;
        }

        return $this->teamAllows('growbag_production', 'view');
    }

    public function canViewAssets(): bool
    {
        if ($this->isSuperadmin()) {
            return true;
        }

        return $this->teamAllows('asset_tracking', 'view');
    }

    /**
     * Whether the user may add or edit attendance records.
     */
    public function canManageAttendance(): bool
    {
        if ($this->isSuperadmin()) {
            return true;
        }

        return $this->teamAllowsAny([
            ['attendance', 'create'],
            ['attendance', 'edit'],
            ['attendance', 'salary_report'],
        ]);
    }

    /**
     * Whether the user may close a pith location's stock.
     */
    public function canCloseLocationStock(): bool
    {
        if ($this->writesWithdrawn()) {
            return false;
        }

        if ($this->isSuperadmin()) {
            return true;
        }

        return $this->canViewPithInventory()
            && $this->teamAllows('close_location_stock', 'close');
    }

    /**
     * Supervisor tier for production writes that Staff may not do: pith delete,
     * pith taken delete, surplus CRUD, fibre CRUD, pith-blocks CRUD.
     */
    public function canManageSupervisorProduction(): bool
    {
        if ($this->isSuperadmin()) {
            return true;
        }

        return $this->teamAllowsAny([
            ['fibre_bales', 'edit'],
            ['fibre_bales', 'delete'],
            ['pith_blocks', 'edit'],
            ['pith_blocks', 'delete'],
            ['pith_production', 'delete'],
            ['pith_inventory_outward', 'delete'],
        ]);
    }

    /**
     * Whether the user may change factory-wide setup — shifts, asset categories,
     * customizations, devices, water meters and tanks.
     */
    public function canViewFactoryCustomization(): bool
    {
        if ($this->isSuperadmin()) {
            return true;
        }

        return $this->teamAllowsAny([
            ['shift_settings', 'view'],
            ['asset_categories', 'view'],
            ['factory_customizations', 'view'],
        ]);
    }

    public function canManageFactoryCustomization(): bool
    {
        if ($this->writesWithdrawn()) {
            return false;
        }

        if ($this->isSuperadmin()) {
            return true;
        }

        return $this->teamAllowsAny([
            ['shift_settings', 'create'],
            ['shift_settings', 'edit'],
            ['shift_settings', 'delete'],
            ['asset_categories', 'create'],
            ['asset_categories', 'edit'],
            ['asset_categories', 'delete'],
            ['factory_customizations', 'edit'],
        ]);
    }

    public function canManageFactorySetup(): bool
    {
        return $this->canManageFactoryCustomization();
    }

    public function canViewNotificationPreferences(): bool
    {
        if ($this->isSuperadmin()) {
            return true;
        }

        return $this->teamAllows('notification_preferences', 'edit');
    }

    public function canManageNotificationPreferences(): bool
    {
        if ($this->isSuperadmin()) {
            return true;
        }

        return $this->teamAllows('notification_preferences', 'edit');
    }

    public function canManageUsers(): bool
    {
        if ($this->writesWithdrawn()) {
            return false;
        }

        if ($this->isSuperadmin()) {
            return true;
        }

        return $this->teamAllowsAny([
            ['manage_users', 'create'],
            ['manage_users', 'edit'],
            ['manage_users', 'delete'],
        ]);
    }

    /**
     * Whether the Users nav item / page should be visible: any of
     * view/create/edit/delete on manage_users. Unlike canManageUsers(), this
     * is not affected by writesWithdrawn() — it gates visibility, not writes.
     */
    public function canViewUsers(): bool
    {
        if ($this->isSuperadmin()) {
            return true;
        }

        return $this->teamAllowsAny([
            ['manage_users', 'view'],
            ['manage_users', 'create'],
            ['manage_users', 'edit'],
            ['manage_users', 'delete'],
        ]);
    }

    public function canEditRoleMatrix(): bool
    {
        if ($this->writesWithdrawn()) {
            return false;
        }

        if ($this->isSuperadmin()) {
            return true;
        }

        return $this->getCurrentTeamRole() === 'owner';
    }

    public function canViewRoleMatrix(): bool
    {
        if ($this->isSuperadmin()) {
            return true;
        }

        if ($this->getCurrentTeamRole() === 'owner') {
            return true;
        }

        return $this->teamAllows('manage_users', 'view');
    }

    public function canManageStaff(): bool
    {
        if ($this->writesWithdrawn()) {
            return false;
        }

        if ($this->isSuperadmin()) {
            return true;
        }

        return $this->teamAllowsAny([
            ['manage_staff', 'create'],
            ['manage_staff', 'edit'],
            ['manage_staff', 'delete'],
        ]);
    }

    /**
     * Whether the Staff nav item / page should be visible: any of
     * view/create/edit/delete on manage_staff. Unlike canManageStaff(), this
     * is not affected by writesWithdrawn() — it gates visibility, not writes.
     */
    public function canViewStaff(): bool
    {
        if ($this->isSuperadmin()) {
            return true;
        }

        return $this->teamAllowsAny([
            ['manage_staff', 'view'],
            ['manage_staff', 'create'],
            ['manage_staff', 'edit'],
            ['manage_staff', 'delete'],
        ]);
    }

    public function canManagePithLocationOperations(): bool
    {
        if ($this->writesWithdrawn()) {
            return false;
        }

        if ($this->isSuperadmin()) {
            return true;
        }

        return $this->teamAllowsAny([
            ['pith_location_groups', 'create'],
            ['pith_location_groups', 'edit'],
            ['pith_location_groups', 'delete'],
            ['pith_locations', 'create'],
            ['pith_locations', 'edit'],
            ['pith_locations', 'delete'],
        ]);
    }

    /**
     * Whether the Pith Locations nav item / page should be visible: any of
     * view/create/edit/delete on pith_locations or pith_location_groups.
     * Unlike canManagePithLocationOperations(), this is not affected by
     * writesWithdrawn() — it gates visibility, not writes.
     */
    public function canViewPithLocationOperations(): bool
    {
        if ($this->isSuperadmin()) {
            return true;
        }

        return $this->teamAllowsAny([
            ['pith_locations', 'view'],
            ['pith_locations', 'create'],
            ['pith_locations', 'edit'],
            ['pith_locations', 'delete'],
            ['pith_location_groups', 'view'],
            ['pith_location_groups', 'create'],
            ['pith_location_groups', 'edit'],
            ['pith_location_groups', 'delete'],
        ]);
    }

    /**
     * The roles this user may assign when creating or editing a team member.
     *
     * An admin manages the roles beneath them; only an owner may create, edit or
     * delete another admin. Ownership itself transfers through its own flow.
     *
     * @return list<string>
     */
    public function assignableRoles(): array
    {
        if ($this->writesWithdrawn()) {
            return [];
        }

        if ($this->isSuperadmin() || $this->canManageUsers()) {
            return ['admin', 'supervisor', 'staff', 'reader'];
        }

        return [];
    }

    /**
     * Whether this user may create, edit or delete a member holding $role.
     */
    public function canManageUserWithRole(?string $role): bool
    {
        return in_array($role, $this->assignableRoles(), true);
    }

    /**
     * Whether the user may hand factory ownership to another member.
     *
     * Kept out of assignableRoles(): ownership moves through its own transfer
     * flow, which demotes the outgoing owner to admin, rather than through the
     * role form. An owner transferring ownership gives away their own access, so
     * this is the one capability that can demote the person exercising it.
     */
    public function canTransferOwnership(): bool
    {
        if ($this->writesWithdrawn()) {
            return false;
        }

        return $this->isSuperadmin();
    }

    /**
     * Check if the user is a supervisor
     */
    public function isSupervisorRole(): bool
    {
        return $this->getCurrentTeamRole() === 'supervisor';
    }

    public function currentTeam()
    {
        return $this->belongsTo(Team::class, 'current_team_id');
    }

    /**
     * The team whose context this request runs in.
     *
     * `current_team_id` is not that team: SetActiveTeam deliberately never
     * persists it, so it still points at whichever team an explicit switch last
     * wrote. On routes carrying no `{team}` segment (teams.create,
     * livewire/update) that stale id would silently drag the user into another
     * factory, so prefer the `last_team_id` cookie SetActiveTeam refreshes on
     * every team-scoped response.
     */
    public function activeTeam(): ?Team
    {
        if (app()->has('current.team')) {
            return app('current.team');
        }

        $lastTeamId = request()->cookie('last_team_id');

        if ($lastTeamId && (int) $lastTeamId !== (int) $this->current_team_id) {
            $team = Team::find($lastTeamId);

            // The cookie is client-supplied, so it never grants access on its own.
            if ($team && $this->canAccessTeam($team)) {
                return $team;
            }
        }

        return $this->currentTeam;
    }

    /**
     * Whether this user may view the given team.
     *
     * Queried directly instead of via Jetstream's belongsToTeam() so callers on
     * the request hot path don't hydrate every team relation.
     */
    public function canAccessTeam(Team $team): bool
    {
        return (bool) $this->is_superadmin
            || $this->teams()->where('teams.id', $team->id)->exists()
            || $this->ownedTeams()->where('teams.id', $team->id)->exists();
    }

    public function ownsTeam($team): bool
    {
        // Ownership is determined by team_user.role = 'owner', not teams.user_id
        return DB::table('team_user')
            ->where('team_id', $team->id)
            ->where('user_id', $this->id)
            ->where('role', 'owner')
            ->exists();
    }

    /**
     * Override Jetstream's ownedTeams() which normally relies on teams.user_id.
     * Returns all teams where this user has the 'owner' role in team_user.
     */
    public function ownedTeams()
    {
        return $this->belongsToMany(Team::class, 'team_user')
            ->wherePivot('role', 'owner');
    }

    /**
     * Columns outside the books_* module that point at a user and hold real
     * business records. A user referenced here must be kept for history.
     *
     * @var array<string, list<string>>
     */
    public const RECORD_REFERENCES = [
        'impersonation_logs' => ['impersonator_id', 'impersonated_id'],
        'pulse_geofence_events' => ['user_id'],
        'pulse_geofence_mobile_logs' => ['user_id'],
        'pulse_husks_daily_stock' => ['user_id'],
        'pulse_asset_outwards' => ['approved_by'],
        'pulse_attendance' => ['created_by', 'updated_by'],
        'pulse_devices' => ['created_by', 'updated_by'],
        'pulse_master_growbag_machines' => ['created_by', 'updated_by'],
        'pulse_master_minibale_machines' => ['created_by', 'updated_by'],
        'pulse_pith_inventory' => ['created_by', 'updated_by'],
        'pulse_pith_stock_depletions' => ['created_by', 'updated_by'],
        'pulse_settings' => ['created_by', 'updated_by'],
        'pulse_shifts' => ['created_by', 'updated_by'],
        'pulse_summary_mini_bales' => ['created_by', 'updated_by'],
        'pulse_summary_pith_productions' => ['created_by', 'updated_by'],
        'staffs' => ['created_by', 'updated_by'],
    ];

    /**
     * Whether any production, inventory, audit or master-data row references this user.
     */
    public function hasAssociatedRecords(): bool
    {
        foreach (self::RECORD_REFERENCES as $table => $columns) {
            $query = DB::table($table);

            $query->where(function ($q) use ($columns) {
                foreach ($columns as $column) {
                    $q->orWhere($column, $this->id);
                }
            });

            if ($query->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * A stale account: not a superadmin, in no team, and referenced by no records,
     * so deleting it loses nothing.
     */
    public function isDeletableOrphan(): bool
    {
        if ($this->isSuperadmin()) {
            return false;
        }

        $hasTeams = $this->relationLoaded('teams')
            ? $this->teams->isNotEmpty()
            : $this->teams()->exists();

        return ! $hasTeams && ! $this->hasAssociatedRecords();
    }

    public function pithProductions(): HasMany
    {
        return $this->hasMany(PithProduction::class);
    }

    public function miniBaleProductions(): HasMany
    {
        return $this->hasMany(MiniBaleProduction::class);
    }

    public function teams()
    {
        return $this->belongsToMany(Team::class, 'team_user')->withPivot('role');
    }

    public function allTeams()
    {
        // If super admin, return all teams
        if ($this->is_superadmin) {
            return Team::orderBy('name')->get();
        }

        // Otherwise, default Jetstream logic
        return $this->ownedTeams
            ->merge($this->teams)
            ->sortBy('name')
            ->values();
    }

    public function switchTeam($team)
    {
        if (! $team instanceof Team) {
            $team = Team::findOrFail($team);
        }

        // Check membership unless superadmin
        if (! $this->is_superadmin && ! $this->belongsToTeam($team)) {
            return false;
        }

        $this->forceFill([
            'current_team_id' => $team->id,
        ])->save();

        $this->setRelation('currentTeam', $team);

        return true;
    }
}
