<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Impersonation;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

/**
 * Authorization core for Pulse.
 *
 * Two layers of gates exist here:
 *
 * 1. Fine-grained `module.action` gates (e.g. `fibre_bales.delete`,
 *    `pith_production.create`) are NOT individually defined below. They are
 *    resolved generically by the Gate::before callback registered in boot(),
 *    which matches the ability name against the module catalog in
 *    config('pulse_role_permissions.modules') and defers to
 *    User::allows($module, $action). Every mutation controller and Livewire
 *    component should authorize against one of these — they are the single
 *    source of truth for who may create/edit/delete/etc. a given resource,
 *    and they read live from the per-team role permission matrix an owner
 *    configures on the Roles page.
 *
 * 2. Coarse, hand-named gates defined explicitly below. These fall into
 *    four groups:
 *      - App exceptions: concerns outside the per-team role matrix entirely
 *        (superadmin-only settings, impersonation, factory-configuration
 *        writes reserved for superadmins).
 *      - 1:1 aliases: a single readable gate name wrapping exactly one
 *        matrix capability check, kept so routes/blades don't need to spell
 *        out the underlying module/action pair (e.g. `view-attendance` is
 *        `attendance.view`).
 *      - Nav OR gates: "should this show up in navigation/section" checks
 *        that are true if the user holds ANY of several underlying
 *        capabilities (e.g. `view-users` is true for anyone who can view,
 *        create, edit, or delete users).
 *      - Legacy coarse gates (BC only): pre-refactor gates such as
 *        `manage-records` and `supervisor-production` that OR together many
 *        capabilities. They remain for nav sections and any callers not yet
 *        migrated, but MUST NOT be used to authorize a specific mutation —
 *        the mutation should check the matching `module.action` gate
 *        instead, since a coarse OR is deliberately looser than any single
 *        capability (e.g. `supervisor-production` is true for a user who can
 *        only edit fibre bales, even for a fibre-bale DELETE they don't
 *        hold).
 */
class AuthServiceProvider extends ServiceProvider
{
    /**
     * The policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        // 'App\Models\Model' => 'App\Policies\ModelPolicy',
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        $this->registerPolicies();

        // Generic `module.action` resolver — see class docblock, group 1.
        Gate::before(function (User $user, string $ability) {
            if (! preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', $ability)) {
                return null;
            }

            [$module, $action] = explode('.', $ability, 2);

            if (! array_key_exists($module, config('pulse_role_permissions.modules', []))) {
                return null;
            }

            return $user->allows($module, $action);
        });

        // ── App exceptions — outside the per-team role matrix ──────────────
        Gate::define('view-superadmin-settings', function (User $user) {
            return $user->canAccessSuperadminSettings();
        });

        Gate::define('manage-factory-configuration', function (User $user) {
            return $user->canManageFactoryConfiguration();
        });

        // only superadmins may impersonate — and never while already impersonating
        Gate::define('impersonate-users', function (User $user) {
            return $user->isSuperadmin() && ! Impersonation::isActive();
        });

        // ── 1:1 aliases — one gate name, one matrix capability ─────────────
        Gate::define('edit-factory-configuration', function (User $user) {
            return $user->canEditFactoryConfiguration();
        });

        Gate::define('view-notification-preferences', function (User $user) {
            return $user->canViewNotificationPreferences();
        });

        Gate::define('manage-notification-preferences', function (User $user) {
            return $user->canManageNotificationPreferences();
        });

        Gate::define('manage-husk-stock', function (User $user) {
            return $user->canManageHuskStock();
        });

        Gate::define('view-attendance', function (User $user) {
            return $user->canViewAttendance();
        });

        Gate::define('view-pith-production', function (User $user) {
            return $user->canViewPithProduction();
        });

        Gate::define('view-fibre-bales', function (User $user) {
            return $user->canViewFibreBales();
        });

        Gate::define('view-bale-inventory', function (User $user) {
            return $user->canAccessFibreBalesInventoryReport();
        });

        Gate::define('view-pith-blocks', function (User $user) {
            return $user->canViewPithBlocks();
        });

        Gate::define('view-block-inventory', function (User $user) {
            return $user->canAccessPithBlocksInventoryReport();
        });

        Gate::define('view-husk-inventory', function (User $user) {
            return $user->canViewHuskInventory();
        });

        Gate::define('view-growbag-production', function (User $user) {
            return $user->canViewGrowbagProduction();
        });

        Gate::define('view-assets', function (User $user) {
            return $user->canViewAssets();
        });

        Gate::define('close-location-stock', function (User $user) {
            return $user->canCloseLocationStock();
        });

        Gate::define('view-role-matrix', function (User $user) {
            return $user->canViewRoleMatrix();
        });

        // ── Nav OR gates — "show this nav item/section" checks ─────────────
        Gate::define('view-settings', function (User $user) {
            return $user->canAccessSettings();
        });

        Gate::define('view-factory-configuration', function (User $user) {
            return $user->canViewFactoryConfiguration();
        });

        Gate::define('view-factory-customization', function (User $user) {
            return $user->canViewFactoryCustomization();
        });

        Gate::define('view-pith-inventory', function (User $user) {
            return $user->canViewPithInventory();
        });

        Gate::define('view-users', function (User $user) {
            return $user->canViewUsers();
        });

        Gate::define('view-staff', function (User $user) {
            return $user->canViewStaff();
        });

        Gate::define('view-pith-locations', function (User $user) {
            return $user->canViewPithLocationOperations();
        });

        // ── Legacy coarse gates (BC) — nav/section only, never a mutation ──
        Gate::define('manage-users', function (User $user) {
            return $user->canManageUsers();
        });

        Gate::define('manage-staff', function (User $user) {
            return $user->canManageStaff();
        });

        Gate::define('manage-pith-location-operations', function (User $user) {
            return $user->canManagePithLocationOperations();
        });

        Gate::define('manage-factory-customization', function (User $user) {
            return $user->canManageFactoryCustomization();
        });

        Gate::define('manage-factory-setup', function (User $user) {
            return $user->canManageFactorySetup();
        });

        Gate::define('manage-attendance', function (User $user) {
            return $user->canManageAttendance();
        });

        // deny readers from add, edit, delete
        Gate::define('manage-records', function (User $user) {
            return $user->canManageRecords();
        });

        // deny staff and reader from delete
        Gate::define('delete-records', function (User $user) {
            return $user->canDeleteRecords();
        });

        // supervisor+: pith delete, taken delete, surplus, fibre, pith-blocks
        Gate::define('supervisor-production', function (User $user) {
            return $user->canManageSupervisorProduction();
        });
    }
}
