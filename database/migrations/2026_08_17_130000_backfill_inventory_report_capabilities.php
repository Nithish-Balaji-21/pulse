<?php

use App\Support\Pulse\TeamRolePermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `pith_inventory:inventory_report` and `husk_inventory:inventory_report` gate the Reports hub
 * inventory cards and routes. Stored role permissions are authoritative once a team has been
 * seeded — an action absent from the flat list reads as false rather than falling back to config
 * defaults. Without this backfill, supervisor+ roles on existing teams would lose report access
 * until an owner re-saves the matrix.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const ROLE_KEYS = ['supervisor', 'admin', 'owner'];

    /** @var array<string, string> */
    private const GRANTS = [
        'pith_inventory' => 'pith_inventory:view',
        'husk_inventory' => 'husk_inventory:update',
    ];

    public function up(): void
    {
        foreach (self::GRANTS as $module => $requires) {
            $capability = "{$module}:inventory_report";

            $this->eachRole(function (int $id, int $teamId, array $permissions) use ($requires, $capability) {
                if (! in_array($requires, $permissions, true) || in_array($capability, $permissions, true)) {
                    return;
                }

                $permissions[] = $capability;
                sort($permissions);

                $this->store($id, $teamId, $permissions);
            });
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::GRANTS) as $module) {
            $capability = "{$module}:inventory_report";

            $this->eachRole(function (int $id, int $teamId, array $permissions) use ($capability) {
                if (! in_array($capability, $permissions, true)) {
                    return;
                }

                $this->store($id, $teamId, array_values(array_filter(
                    $permissions,
                    fn (string $cap) => $cap !== $capability
                )));
            });
        }
    }

    /**
     * @param  callable(int, int, list<string>): void  $callback
     */
    private function eachRole(callable $callback): void
    {
        DB::table('pulse_team_roles')
            ->whereIn('key', self::ROLE_KEYS)
            ->orderBy('id')
            ->each(function (object $role) use ($callback) {
                $permissions = json_decode($role->permissions ?? '', true);

                if (! is_array($permissions) || $permissions === []) {
                    return;
                }

                if (is_array(reset($permissions))) {
                    return;
                }

                $callback((int) $role->id, (int) $role->team_id, array_values($permissions));
            });
    }

    /**
     * @param  list<string>  $permissions
     */
    private function store(int $id, int $teamId, array $permissions): void
    {
        DB::table('pulse_team_roles')->where('id', $id)->update([
            'permissions' => json_encode($permissions),
            'updated_at' => now(),
        ]);

        TeamRolePermissions::forgetCache($teamId);
    }
};
