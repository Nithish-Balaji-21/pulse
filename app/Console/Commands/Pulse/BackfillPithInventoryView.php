<?php

namespace App\Console\Commands\Pulse;

use App\Support\Pulse\TeamModuleContext;
use App\Support\Pulse\TeamRolePermissions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Grant pith_inventory:view to roles that already have any child pith inventory
 * capability (inward, outward, or close stock). New teams pick this up from
 * config defaults via seedDefaultsForTeam.
 *
 * Safe to re-run: skips rows that already have pith_inventory:view after sanitize.
 */
class BackfillPithInventoryView extends Command
{
    protected $signature = 'pulse:backfill-pith-inventory-view
                            {--dry-run : Report rows that would change without writing}';

    protected $description = 'Backfill pith_inventory:view for roles with child inventory permissions';

    public function handle(): int
    {
        if (! Schema::hasTable('pulse_team_roles')) {
            $this->warn('pulse_team_roles table not found; nothing to do.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $updated = 0;
        $touchedTeams = [];

        DB::table('pulse_team_roles')
            ->select(['id', 'team_id', 'key', 'permissions'])
            ->orderBy('id')
            ->chunkById(100, function ($rows) use (&$updated, &$touchedTeams, $dryRun) {
                foreach ($rows as $role) {
                    $permissions = is_string($role->permissions)
                        ? json_decode($role->permissions, true)
                        : $role->permissions;
                    $permissions = is_array($permissions) ? $permissions : [];

                    $flat = $this->flattenPermissions($permissions);

                    $hasChild = collect($flat)->contains(function (string $capability) {
                        return str_starts_with($capability, 'pith_inventory_inward:')
                            || str_starts_with($capability, 'pith_inventory_outward:')
                            || str_starts_with($capability, 'close_location_stock:');
                    });

                    if (! $hasChild && ! in_array('pith_inventory:view', $flat, true)) {
                        continue;
                    }

                    if ($hasChild && ! in_array('pith_inventory:view', $flat, true)) {
                        $flat[] = 'pith_inventory:view';
                    }

                    $ctx = TeamModuleContext::fromTeamId((int) $role->team_id);
                    $sanitized = TeamRolePermissions::sanitizePermissions(
                        (int) $role->team_id,
                        $flat,
                        (string) $role->key,
                        $ctx
                    );

                    $before = $this->flattenPermissions(
                        is_array($permissions) ? $permissions : []
                    );
                    sort($before);
                    $after = $sanitized;
                    sort($after);

                    if ($before === $after) {
                        continue;
                    }

                    $updated++;
                    $touchedTeams[(int) $role->team_id] = true;

                    if ($dryRun) {
                        $this->line("Would update role #{$role->id} ({$role->key}) team {$role->team_id}");

                        continue;
                    }

                    DB::table('pulse_team_roles')
                        ->where('id', $role->id)
                        ->update([
                            'permissions' => json_encode($sanitized, JSON_UNESCAPED_UNICODE),
                            'updated_at' => now(),
                        ]);
                }
            });

        if (! $dryRun) {
            foreach (array_keys($touchedTeams) as $teamId) {
                TeamRolePermissions::forgetCache($teamId);
            }
        }

        $verb = $dryRun ? 'Would update' : 'Updated';
        $this->info("{$verb} {$updated} role row(s).");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, array<string, bool>>|list<string>  $permissions
     * @return list<string>
     */
    private function flattenPermissions(array $permissions): array
    {
        if ($permissions === []) {
            return [];
        }

        $first = reset($permissions);
        if (is_array($first)) {
            $flat = [];
            foreach ($permissions as $module => $actions) {
                if (! is_array($actions)) {
                    continue;
                }
                foreach ($actions as $action => $allowed) {
                    if ($allowed) {
                        $flat[] = $module.':'.$action;
                    }
                }
            }

            return array_values(array_unique($flat));
        }

        return array_values(array_filter($permissions, 'is_string'));
    }
}
