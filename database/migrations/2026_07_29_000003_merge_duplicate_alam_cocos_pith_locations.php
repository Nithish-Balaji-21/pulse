<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Fold duplicate Alam Cocos pith locations into the name that survives, moving
 * their loads and depletion records across before deleting the empty shell.
 *
 * Destructive and not reversible — down() is intentionally a no-op.
 *
 * Every merge is guarded: a source holding live stock is skipped rather than
 * deleted, because this dev-derived list may not match production exactly.
 * Safe to re-run — once a source is gone, its merge is skipped.
 */
return new class extends Migration
{
    private const TEAM_NAME = 'Alam Cocos';

    /**
     * from => the location being retired, into => the one that survives.
     * Names are matched exactly (MySQL collation makes this case-insensitive).
     *
     * @var array<int, array{from: string, into: string}>
     */
    private const MERGES = [
        ['from' => 'alam east - old', 'into' => 'alam east'],
        ['from' => 'Location alam', 'into' => 'alam east'],
        ['from' => 'Alam new2', 'into' => 'Alam New1'],
        ['from' => 'alam north', 'into' => 'Alam north 2'],
        ['from' => 'Loganatha goundar', 'into' => 'Loganatha Gounder'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('pulse_pith_locations')) {
            return;
        }

        $teamId = DB::table('teams')->where('name', self::TEAM_NAME)->value('id');

        if (! $teamId) {
            return;
        }

        foreach (self::MERGES as $merge) {
            $this->merge($teamId, $merge['from'], $merge['into']);
        }
    }

    /**
     * Reversing a merge would mean re-splitting loads that no longer record
     * which location they came from, so this cannot be undone automatically.
     */
    public function down(): void
    {
        //
    }

    private function merge(int $teamId, string $fromName, string $intoName): void
    {
        $source = DB::table('pulse_pith_locations')
            ->where('team_id', $teamId)
            ->where('name', $fromName)
            ->first(['id', 'name']);

        $target = DB::table('pulse_pith_locations')
            ->where('team_id', $teamId)
            ->where('name', $intoName)
            ->first(['id', 'name']);

        // Already merged on an earlier run, or this factory never had the pair.
        if (! $source || ! $target || $source->id === $target->id) {
            return;
        }

        $liveRows = DB::table('pulse_pith_inventory')
            ->where('pith_location_id', $source->id)
            ->where('is_archived', 0)
            ->count();

        $remaining = (float) DB::table('pulse_pith_inventory')
            ->where('pith_location_id', $source->id)
            ->where('is_archived', 0)
            ->selectRaw('COALESCE(SUM(cft_offloaded), 0) - COALESCE(SUM(taken_cft_calculated), 0) AS remaining')
            ->value('remaining');

        if (round($remaining, 2) > 0) {
            Log::warning('Skipped pith location merge: source still holds stock', [
                'from' => $source->name,
                'into' => $target->name,
                'remaining_cft' => round($remaining, 2),
            ]);

            return;
        }

        DB::transaction(function () use ($source, $target, $teamId, $liveRows) {
            $movedLoads = DB::table('pulse_pith_inventory')
                ->where('pith_location_id', $source->id)
                ->update(['pith_location_id' => $target->id, 'updated_at' => now()]);

            $movedDepletions = DB::table('pulse_pith_stock_depletions')
                ->where('pith_location_id', $source->id)
                ->update(['pith_location_id' => $target->id, 'updated_at' => now()]);

            // The source's daily snapshots describe a location that is about to
            // disappear, and the summary table has a foreign key onto it.
            $droppedSummaries = DB::table('pulse_pith_inventory_summary')
                ->where('location_id', $source->id)
                ->delete();

            // Snapshots are built from non-archived loads only. If any moved, the
            // target's existing history no longer reflects its loads, so clear it
            // and let the next pith:inventory-snapshot run rebuild from scratch.
            if ($liveRows > 0) {
                DB::table('pulse_pith_inventory_summary')
                    ->where('location_id', $target->id)
                    ->delete();
            }

            DB::table('pulse_pith_locations')
                ->where('id', $source->id)
                ->where('team_id', $teamId)
                ->delete();

            Log::info('Merged pith location', [
                'from' => $source->name,
                'into' => $target->name,
                'loads_moved' => $movedLoads,
                'depletions_moved' => $movedDepletions,
                'source_summaries_dropped' => $droppedSummaries,
                'target_history_rebuilt' => $liveRows > 0,
            ]);
        });
    }
};
