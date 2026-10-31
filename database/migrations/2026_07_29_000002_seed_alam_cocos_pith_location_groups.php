<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backfill the Alam Cocos pith location groups and assign their existing
 * locations by name.
 *
 * Deliberately self-contained — the ruleset is a frozen snapshot of what was
 * reviewed at the time, so this keeps working even if the equivalent artisan
 * command (pith:seed-location-groups) is later changed or removed.
 *
 * Safe to re-run: groups are matched by (team, name) rather than recreated, and
 * only locations with no group yet are touched, so manual corrections survive.
 */
return new class extends Migration
{
    /**
     * The factory this ruleset belongs to. Resolved by name because team ids
     * differ between environments.
     */
    private const TEAM_NAME = 'Alam Cocos';

    /**
     * Group name => [display order, anchored name prefixes, exact names].
     *
     * Prefixes must end on a word boundary, so "hexagro kalam" cannot be
     * captured by the "alam" rule the way a plain substring match would.
     *
     * @var array<string, array{order: int, prefixes: array<int, string>, exact: array<int, string>}>
     */
    private const RULES = [
        'Alam' => ['order' => 1, 'prefixes' => ['alam'], 'exact' => ['location alam']],
        'Hexagro' => ['order' => 2, 'prefixes' => ['hexagro'], 'exact' => []],
        'Doctor Land' => ['order' => 3, 'prefixes' => ['doctor land', 'dr land'], 'exact' => []],
        'Kannappa Gounder' => ['order' => 4, 'prefixes' => ['kannappa gounder'], 'exact' => []],
        'Loganatha Gounder' => ['order' => 5, 'prefixes' => ['loganatha gounder', 'loganatha goundar'], 'exact' => []],
        'Karanampettai' => ['order' => 6, 'prefixes' => ['karanampettai'], 'exact' => []],
        'Hen Forms' => ['order' => 7, 'prefixes' => ['hen forms', 'hen form'], 'exact' => []],
        'Hari' => ['order' => 8, 'prefixes' => ['hari'], 'exact' => []],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('pulse_pith_location_groups') || ! Schema::hasColumn('pulse_pith_locations', 'group_id')) {
            return;
        }

        $teamId = DB::table('teams')->where('name', self::TEAM_NAME)->value('id');

        if (! $teamId) {
            return;
        }

        $locations = DB::table('pulse_pith_locations')
            ->where('team_id', $teamId)
            ->whereNull('group_id')
            ->select('id', 'name')
            ->get();

        if ($locations->isEmpty()) {
            return;
        }

        $toAssign = [];

        foreach ($locations as $location) {
            $groupName = $this->resolveGroupName($location->name);

            if ($groupName !== null) {
                $toAssign[$groupName][] = $location->id;
            }
        }

        $now = now();

        foreach ($toAssign as $groupName => $locationIds) {
            $groupId = DB::table('pulse_pith_location_groups')
                ->where('team_id', $teamId)
                ->where('name', $groupName)
                ->value('id');

            if (! $groupId) {
                $groupId = DB::table('pulse_pith_location_groups')->insertGetId([
                    'team_id' => $teamId,
                    'name' => $groupName,
                    'display_order' => self::RULES[$groupName]['order'],
                    'is_active' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            DB::table('pulse_pith_locations')
                ->whereIn('id', $locationIds)
                ->where('team_id', $teamId)
                ->whereNull('group_id')
                ->update(['group_id' => $groupId, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('pulse_pith_location_groups')) {
            return;
        }

        $teamId = DB::table('teams')->where('name', self::TEAM_NAME)->value('id');

        if (! $teamId) {
            return;
        }

        // group_id is a nullOnDelete foreign key, so dropping the groups
        // releases their locations back to ungrouped.
        DB::table('pulse_pith_location_groups')
            ->where('team_id', $teamId)
            ->whereIn('name', array_keys(self::RULES))
            ->delete();
    }

    /**
     * Match a location name against the ruleset, or null when nothing applies.
     */
    private function resolveGroupName(string $locationName): ?string
    {
        $normalized = mb_strtolower(preg_replace('/\s+/', ' ', trim($locationName)));

        foreach (self::RULES as $groupName => $rule) {
            if (in_array($normalized, $rule['exact'], true)) {
                return $groupName;
            }

            foreach ($rule['prefixes'] as $prefix) {
                if ($normalized === $prefix || str_starts_with($normalized, $prefix.' ')) {
                    return $groupName;
                }
            }
        }

        return null;
    }
};
