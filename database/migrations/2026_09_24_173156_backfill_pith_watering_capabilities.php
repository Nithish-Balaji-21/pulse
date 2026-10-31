<?php

use App\Support\Pulse\TeamRolePermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `pith_watering:create|edit|delete` gate the mobile app's pith watering log. Stored role
 * permissions are authoritative once a team has been seeded — an action absent from the flat
 * list reads as false rather than falling back to config defaults. Without this backfill, no
 * role on an existing team could log watering until an owner re-saves the matrix.
 *
 * Grants mirror the config defaults, and only to roles that can already see pith inventory.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> */
    private const GRANTS = [
        'staff' => ['pith_watering:create'],
        'supervisor' => ['pith_watering:create', 'pith_watering:edit'],
        'admin' => ['pith_watering:create', 'pith_watering:edit', 'pith_watering:delete'],
        'owner' => ['pith_watering:create', 'pith_watering:edit', 'pith_watering:delete'],
    ];

    public function up(): void
    {
        $this->eachRole(array_keys(self::GRANTS), function (int $id, int $teamId, string $key, array $permissions) {
            if (! in_array('pith_inventory:view', $permissions, true)) {
                return;
            }

            $missing = array_diff(self::GRANTS[$key], $permissions);

            if ($missing === []) {
                return;
            }

            $permissions = array_merge($permissions, array_values($missing));
            sort($permissions);

            $this->store($id, $teamId, $permissions);
        });
    }

    public function down(): void
    {
        $this->eachRole(array_keys(self::GRANTS), function (int $id, int $teamId, string $key, array $permissions) {
            $kept = array_values(array_filter(
                $permissions,
                fn (string $capability) => ! str_starts_with($capability, 'pith_watering:')
            ));

            if (count($kept) === count($permissions)) {
                return;
            }

            $this->store($id, $teamId, $kept);
        });
    }

    /**
     * @param  list<string>  $roleKeys
     * @param  callable(int, int, string, list<string>): void  $callback
     */
    private function eachRole(array $roleKeys, callable $callback): void
    {
        DB::table('pulse_team_roles')
            ->whereIn('key', $roleKeys)
            ->orderBy('id')
            ->each(function (object $role) use ($callback) {
                $permissions = json_decode($role->permissions ?? '', true);

                if (! is_array($permissions) || $permissions === []) {
                    return;
                }

                if (is_array(reset($permissions))) {
                    return;
                }

                $callback((int) $role->id, (int) $role->team_id, (string) $role->key, array_values($permissions));
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
