<?php

use App\Support\Pulse\TeamRolePermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `husk_inventory:daily_reminder` splits the mobile app's daily husk entry popup away from the
 * `husk_inventory:update` write capability it had been riding on.
 *
 * Stored role permissions are authoritative once a team has been seeded — sanitizePermissions
 * expands the stored flat list into a matrix, so an action absent from that list reads as false
 * rather than falling back to the config default. Without this backfill the new capability would
 * be off everywhere and nobody would get the prompt.
 *
 * The grant goes to the supervisor role only, which restores the behaviour that held before the
 * role-based refactor replaced an `isSupervisorRole()` check with the `update` gate and started
 * prompting admins and owners too. Teams whose supervisor cannot update husk stock are skipped —
 * there is nothing to remind them to do.
 */
return new class extends Migration
{
    private const CAPABILITY = 'husk_inventory:daily_reminder';

    private const REQUIRES = 'husk_inventory:update';

    public function up(): void
    {
        $this->eachSupervisorRole(function (int $id, int $teamId, array $permissions) {
            if (! in_array(self::REQUIRES, $permissions, true) || in_array(self::CAPABILITY, $permissions, true)) {
                return;
            }

            $permissions[] = self::CAPABILITY;
            sort($permissions);

            $this->store($id, $teamId, $permissions);
        });
    }

    public function down(): void
    {
        $this->eachSupervisorRole(function (int $id, int $teamId, array $permissions) {
            if (! in_array(self::CAPABILITY, $permissions, true)) {
                return;
            }

            $this->store($id, $teamId, array_values(array_filter(
                $permissions,
                fn (string $capability) => $capability !== self::CAPABILITY
            )));
        });
    }

    /**
     * Read straight from the table rather than through the model so the migration does not depend
     * on the current shape of the config catalog.
     *
     * @param  callable(int, int, list<string>): void  $callback
     */
    private function eachSupervisorRole(callable $callback): void
    {
        DB::table('pulse_team_roles')
            ->where('key', 'supervisor')
            ->orderBy('id')
            ->each(function (object $role) use ($callback) {
                $permissions = json_decode($role->permissions ?? '', true);

                if (! is_array($permissions) || $permissions === []) {
                    return;
                }

                // Only the flat capability-list form is backfilled; a matrix-shaped payload is
                // re-derived from the config defaults on its next sanitize pass anyway.
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
