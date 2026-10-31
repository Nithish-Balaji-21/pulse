<?php

namespace Tests\Pulse\Support;

use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Catalog of mobile API routes guarded by can: middleware in routes/api.php.
 *
 * Used by RbacMobileApiTest to assert pulse_team_roles defaults per role.
 */
class MobileApiRbacMatrix
{
    /**
     * @return list<array{module: string, method: string, path: string, gates: list<string>, allowed_status: int, open?: bool, notes?: string}>
     */
    public static function routes(): array
    {
        return [
            // Pith production
            ['module' => 'pith_production', 'method' => 'GET', 'path' => 'pith', 'gates' => ['view-pith-production'], 'allowed_status' => 200, 'notes' => 'list'],
            ['module' => 'pith_production', 'method' => 'GET', 'path' => 'pith/add', 'gates' => ['pith_production.create'], 'allowed_status' => 200, 'notes' => 'create form'],
            ['module' => 'pith_production', 'method' => 'DELETE', 'path' => 'pith/delete/999999', 'gates' => ['pith_production.delete'], 'allowed_status' => 404, 'notes' => 'delete missing'],
            ['module' => 'pith_production', 'method' => 'GET', 'path' => 'pith/inventory/999999', 'gates' => ['view-pith-production'], 'allowed_status' => 404, 'notes' => 'assignment modal'],

            // Pith inventory
            ['module' => 'pith_inventory', 'method' => 'GET', 'path' => 'pith/inventory', 'gates' => ['view-pith-inventory'], 'allowed_status' => 200, 'notes' => 'inventory list'],
            ['module' => 'pith_inventory_inward', 'method' => 'POST', 'path' => 'pith/surplus/store', 'gates' => ['view-pith-inventory', 'pith_inventory_inward.create'], 'allowed_status' => 422, 'allowed_statuses' => [422, 400], 'notes' => 'surplus create'],
            ['module' => 'pith_inventory_outward', 'method' => 'POST', 'path' => 'pith/taken/store', 'gates' => ['view-pith-inventory', 'pith_inventory_outward.create'], 'allowed_status' => 422, 'allowed_statuses' => [422, 400], 'notes' => 'taken create'],
            ['module' => 'close_location_stock', 'method' => 'POST', 'path' => 'pith/closelocationstock/1', 'gates' => ['view-pith-inventory', 'close_location_stock.close'], 'allowed_status' => 404, 'notes' => 'close stock'],
            ['module' => 'pith_watering', 'method' => 'POST', 'path' => 'pith/watering/store', 'gates' => ['view-pith-inventory', 'pith_watering.create'], 'allowed_status' => 422, 'notes' => 'watering create'],
            ['module' => 'pith_watering', 'method' => 'PUT', 'path' => 'pith/watering/update/999999', 'gates' => ['view-pith-inventory', 'pith_watering.edit'], 'allowed_status' => 404, 'notes' => 'watering update missing'],
            ['module' => 'pith_watering', 'method' => 'DELETE', 'path' => 'pith/watering/delete/999999', 'gates' => ['view-pith-inventory', 'pith_watering.delete'], 'allowed_status' => 404, 'notes' => 'watering delete missing'],

            // Pith blocks
            ['module' => 'pith_blocks', 'method' => 'GET', 'path' => 'pith-blocks', 'gates' => ['pith_blocks.view'], 'allowed_status' => 200, 'notes' => 'list'],
            ['module' => 'pith_blocks', 'method' => 'GET', 'path' => 'pith-blocks/add', 'gates' => ['pith_blocks.create'], 'allowed_status' => 200, 'notes' => 'create form'],
            ['module' => 'pith_blocks', 'method' => 'DELETE', 'path' => 'pith-blocks/delete/999999', 'gates' => ['pith_blocks.delete'], 'allowed_status' => 400, 'notes' => 'delete missing'],

            // Fibre bales
            ['module' => 'fibre_bales', 'method' => 'GET', 'path' => 'fibre', 'gates' => ['fibre_bales.view'], 'allowed_status' => 200, 'notes' => 'list'],
            ['module' => 'fibre_bales', 'method' => 'GET', 'path' => 'fibre/add', 'gates' => ['fibre_bales.create'], 'allowed_status' => 200, 'notes' => 'create form'],
            ['module' => 'fibre_bales', 'method' => 'DELETE', 'path' => 'fibre/delete/999999', 'gates' => ['fibre_bales.delete'], 'allowed_status' => 400, 'notes' => 'delete missing'],

            // Assets
            ['module' => 'asset_tracking', 'method' => 'GET', 'path' => 'assets', 'gates' => ['asset_tracking.view'], 'allowed_status' => 200, 'notes' => 'list'],
            ['module' => 'asset_tracking', 'method' => 'GET', 'path' => 'assets/add', 'gates' => ['asset_tracking.create'], 'allowed_status' => 200, 'notes' => 'create form'],
            ['module' => 'asset_tracking', 'method' => 'DELETE', 'path' => 'assets/delete/999999', 'gates' => ['asset_tracking.delete'], 'allowed_status' => 400, 'notes' => 'delete missing'],

            // Attendance
            ['module' => 'attendance', 'method' => 'GET', 'path' => 'attendance', 'gates' => ['attendance.view'], 'allowed_status' => 200, 'notes' => 'summary'],
            ['module' => 'attendance', 'method' => 'GET', 'path' => 'attendance/add', 'gates' => ['attendance.edit'], 'allowed_status' => 200, 'notes' => 'add form'],
            ['module' => 'attendance', 'method' => 'DELETE', 'path' => 'attendance/delete/999999', 'gates' => ['attendance.edit'], 'allowed_status' => 400, 'notes' => 'delete missing'],

            // Staff & users
            ['module' => 'manage_staff', 'method' => 'GET', 'path' => 'staffs', 'gates' => ['view-staff'], 'allowed_status' => 200, 'notes' => 'list'],
            ['module' => 'manage_staff', 'method' => 'POST', 'path' => 'staffs/store', 'gates' => ['manage_staff.create'], 'allowed_status' => 422, 'allowed_statuses' => [422, 400, 500], 'notes' => 'create'],
            ['module' => 'manage_users', 'method' => 'GET', 'path' => 'users', 'gates' => ['view-users'], 'allowed_status' => 200, 'notes' => 'list'],

            // Factory setup
            ['module' => 'factory_customizations', 'method' => 'GET', 'path' => 'settings', 'gates' => ['factory_customizations.view'], 'allowed_status' => 200, 'notes' => 'settings'],
            ['module' => 'shift_settings', 'method' => 'GET', 'path' => 'shifts', 'gates' => ['shift_settings.view'], 'allowed_status' => 200, 'notes' => 'shifts'],
            ['module' => 'pith_locations', 'method' => 'GET', 'path' => 'pithlocations', 'gates' => ['view-pith-locations'], 'allowed_status' => 200, 'notes' => 'locations'],
            ['module' => 'pith_location_groups', 'method' => 'GET', 'path' => 'pithlocationgroups', 'gates' => ['view-pith-locations'], 'allowed_status' => 200, 'notes' => 'location groups'],

            // Husk
            ['module' => 'husk_inventory', 'method' => 'POST', 'path' => 'husks-daily-stock/update', 'gates' => ['husk_inventory.update'], 'allowed_status' => 422, 'allowed_statuses' => [422, 400], 'notes' => 'update'],

            // Geofence — view gated
            ['module' => 'geofence_locations', 'method' => 'GET', 'path' => 'teams/{team}/geofence-presence', 'gates' => ['geofence_locations.view'], 'allowed_status' => 200, 'notes' => 'presence'],
            ['module' => 'geofence_locations', 'method' => 'POST', 'path' => 'teams/{team}/geofence-locations', 'gates' => ['geofence_locations.create'], 'allowed_status' => 422, 'allowed_statuses' => [422, 400], 'notes' => 'create'],

            // Geofence — open to all team members (staff tracking)
            ['module' => 'geofence_locations', 'method' => 'GET', 'path' => 'teams/{team}/geofence-locations/assigned', 'gates' => [], 'allowed_status' => 200, 'open' => true, 'notes' => 'assigned'],
        ];
    }

    /**
     * Resolve path placeholders for a team.
     */
    public static function resolvePath(string $path, int $teamId): string
    {
        return str_replace('{team}', (string) $teamId, $path);
    }

    /**
     * Whether the user passes all gates on this route.
     *
     * @param  list<string>  $gates
     */
    public static function passesGates(User $user, array $gates): bool
    {
        if ($gates === []) {
            return true;
        }

        foreach ($gates as $gate) {
            if (! Gate::forUser($user)->allows($gate)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Expected HTTP status for a role on a route under default pulse_team_roles.
     */
    public static function expectedStatus(User $user, array $route): int
    {
        if (! self::passesGates($user, $route['gates'])) {
            return 403;
        }

        $allowed = $route['allowed_status'];

        $roleKey = $user->getCurrentTeamRole();
        if ($roleKey !== null && isset($route['allowed_status_by_role'][$roleKey])) {
            return $route['allowed_status_by_role'][$roleKey];
        }

        return $allowed;
    }

    /**
     * Human-readable label for one route × role row (shown in Pest output).
     */
    public static function rowLabel(array $route, string $role): string
    {
        $notes = $route['notes'] ?? $route['module'];
        $gates = $route['gates'] === [] ? 'open' : implode('+', $route['gates']);

        return sprintf(
            '%s | %s %s | %s (%s) [%s]',
            $role,
            $route['method'],
            $route['path'],
            $route['module'],
            $notes,
            $gates
        );
    }

    /**
     * Pest named dataset: label => [route, role] for every route × role.
     *
     * @return array<string, array{0: array, 1: string}>
     */
    public static function pestDataset(): array
    {
        $dataset = [];

        foreach (self::routes() as $route) {
            foreach (RbacFixtures::ROLES as $role) {
                $dataset[self::rowLabel($route, $role)] = [$route, $role];
            }
        }

        return $dataset;
    }

    /**
     * @param  array<string, User>  $users
     * @return list<array{0: array, 1: string, 2: int}>
     */
    public static function dataset(int $teamId, array $users): array
    {
        $rows = [];

        foreach (self::routes() as $route) {
            foreach (RbacFixtures::ROLES as $role) {
                $user = $users[$role];
                $rows[] = [
                    $route,
                    $role,
                    self::expectedStatus($user, $route),
                ];
            }
        }

        return $rows;
    }
}
