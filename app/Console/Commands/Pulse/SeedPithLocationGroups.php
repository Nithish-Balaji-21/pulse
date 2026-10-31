<?php

namespace App\Console\Commands\Pulse;

use App\Models\Pulse\PithLocation;
use App\Models\Pulse\PithLocationGroup;
use App\Models\Team;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off backfill that creates the Alam Cocos pith location groups and assigns
 * their existing locations by name.
 *
 * Safe to re-run: groups are matched by (team, name) rather than recreated, and
 * only locations with no group yet are touched — manual corrections always win.
 */
class SeedPithLocationGroups extends Command
{
    protected $signature = 'pith:seed-location-groups
                            {--team=2 : Team the ruleset belongs to (Alam Cocos)}
                            {--dry-run : Print the proposed mapping without writing}';

    protected $description = 'Create pith location groups and assign ungrouped locations by name';

    /**
     * Group name => [display order, name prefixes, exact names].
     *
     * Prefixes are anchored to the start of the name and must end on a word
     * boundary, so "hexagro kalam" cannot be captured by the "alam" rule.
     *
     * @var array<string, array{order: int, prefixes: array<int, string>, exact: array<int, string>}>
     */
    private const RULES = [
        'Alam' => [
            'order' => 1,
            'prefixes' => ['alam'],
            'exact' => ['location alam'],
        ],
        'Hexagro' => [
            'order' => 2,
            'prefixes' => ['hexagro'],
            'exact' => [],
        ],
        'Doctor Land' => [
            'order' => 3,
            'prefixes' => ['doctor land', 'dr land'],
            'exact' => [],
        ],
        'Kannappa Gounder' => [
            'order' => 4,
            'prefixes' => ['kannappa gounder'],
            'exact' => [],
        ],
        'Loganatha Gounder' => [
            'order' => 5,
            'prefixes' => ['loganatha gounder', 'loganatha goundar'],
            'exact' => [],
        ],
        'Karanampettai' => [
            'order' => 6,
            'prefixes' => ['karanampettai'],
            'exact' => [],
        ],
        'Hen Forms' => [
            'order' => 7,
            'prefixes' => ['hen forms', 'hen form'],
            'exact' => [],
        ],
        'Hari' => [
            'order' => 8,
            'prefixes' => ['hari'],
            'exact' => [],
        ],
    ];

    public function handle(): int
    {
        $teamId = (int) $this->option('team');
        $team = Team::find($teamId);

        if (! $team) {
            $this->error("Team {$teamId} does not exist.");

            return self::FAILURE;
        }

        $locations = PithLocation::where('team_id', $teamId)
            ->orderBy('name')
            ->get();

        if ($locations->isEmpty()) {
            $this->warn("Team {$teamId} ({$team->name}) has no pith locations.");

            return self::SUCCESS;
        }

        $rows = [];
        $toAssign = [];
        $unmatched = [];

        foreach ($locations as $location) {
            $proposed = $this->resolveGroupName($location->name);

            if ($location->group_id !== null) {
                $rows[] = [$location->name, $location->group?->name ?? '—', '—', 'already grouped'];

                continue;
            }

            if ($proposed === null) {
                $rows[] = [$location->name, '—', '—', 'no rule matched'];
                $unmatched[] = $location->name;

                continue;
            }

            $rows[] = [$location->name, '—', $proposed, 'assign'];
            $toAssign[$proposed][] = $location->id;
        }

        $this->info("Team {$teamId} — {$team->name}");
        $this->newLine();
        $this->table(['Location', 'Current group', 'Proposed group', 'Action'], $rows);

        $assignCount = collect($toAssign)->flatten()->count();

        $this->newLine();
        $this->line("Locations: {$locations->count()}  ·  to assign: {$assignCount}  ·  unmatched: ".count($unmatched));

        if (! empty($unmatched)) {
            $this->warn('No rule matched: '.implode(', ', $unmatched));
        }

        if ($assignCount === 0) {
            $this->info('Nothing to assign — every location already has a group or no rule matched.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->comment('Dry run — nothing was written.');

            return self::SUCCESS;
        }

        if (! $this->confirm('Create '.count($toAssign)." group(s) and assign {$assignCount} location(s)?", false)) {
            $this->comment('Aborted — nothing was written.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($teamId, $toAssign) {
            foreach ($toAssign as $groupName => $locationIds) {
                $group = PithLocationGroup::firstOrCreate(
                    ['team_id' => $teamId, 'name' => $groupName],
                    ['display_order' => self::RULES[$groupName]['order']]
                );

                // Re-check the null guard at write time so a concurrent manual
                // assignment made while the table was on screen is not clobbered.
                $updated = PithLocation::whereIn('id', $locationIds)
                    ->where('team_id', $teamId)
                    ->whereNull('group_id')
                    ->update(['group_id' => $group->id]);

                $this->line("  {$groupName}: {$updated} location(s) assigned");
            }
        });

        $this->newLine();
        $this->info('pith:seed-location-groups completed ✓');

        return self::SUCCESS;
    }

    /**
     * Match a location name against the ruleset, or null when nothing applies.
     */
    public function resolveGroupName(string $locationName): ?string
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
}
