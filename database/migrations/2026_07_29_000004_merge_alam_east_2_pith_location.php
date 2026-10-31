<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Fold "alam east 2" into "alam east", the same guarded merge the earlier
 * duplicate clean-up performed. Kept as its own migration so the previous one
 * stays a faithful record of what already ran.
 *
 * Destructive and not reversible — down() is intentionally a no-op.
 * A source holding live stock is skipped rather than deleted.
 */
return new class extends Migration
{
    private const TEAM_NAME = 'Alam Cocos';

    private const FROM = 'alam east 2';

    private const INTO = 'alam east';

    public function up(): void
    {
        if (! Schema::hasTable('pulse_pith_locations')) {
            return;
        }

        $teamId = DB::table('teams')->where('name', self::TEAM_NAME)->value('id');

        if (! $teamId) {
            return;
        }

        $source = DB::table('pulse_pith_locations')
            ->where('team_id', $teamId)->where('name', self::FROM)->first(['id', 'name']);

        $target = DB::table('pulse_pith_locations')
            ->where('team_id', $teamId)->where('name', self::INTO)->first(['id', 'name']);

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

            DB::table('pulse_pith_inventory_summary')->where('location_id', $source->id)->delete();

            // Snapshots read non-archived loads only; if any moved, the target's
            // history is stale and the next snapshot run should rebuild it.
            if ($liveRows > 0) {
                DB::table('pulse_pith_inventory_summary')->where('location_id', $target->id)->delete();
            }

            DB::table('pulse_pith_locations')
                ->where('id', $source->id)->where('team_id', $teamId)->delete();

            Log::info('Merged pith location', [
                'from' => $source->name,
                'into' => $target->name,
                'loads_moved' => $movedLoads,
                'depletions_moved' => $movedDepletions,
                'target_history_rebuilt' => $liveRows > 0,
            ]);
        });
    }

    public function down(): void
    {
        //
    }
};
