<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pith blocks is one row per production day, so shift_name is unused.
 * Keep this separate from the Total Shift collapse migration.
 *
 * Idempotent for schema-dump CI (column already absent). After drop, rechain the
 * blocks ledger so any prior raw DB merges that skipped model observers stay correct.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pulse_summary_pith_blocks')) {
            return;
        }

        $hadShiftName = Schema::hasColumn('pulse_summary_pith_blocks', 'shift_name');

        if ($hadShiftName) {
            Schema::table('pulse_summary_pith_blocks', function (Blueprint $table) {
                $table->dropColumn('shift_name');
            });
        }

        // Reconcile only when we actually changed live data shape in this pair of
        // migrations (shift_name was present). Schema-dump DBs no-op here.
        if (! $hadShiftName || ! Schema::hasTable('pulse_blocks_daily_stock')) {
            return;
        }

        $teams = DB::table('pulse_summary_pith_blocks')
            ->select('team_id', DB::raw('MIN(shift_date) as earliest'))
            ->groupBy('team_id')
            ->get();

        foreach ($teams as $team) {
            DB::statement('CALL reconcile_blocks_stock(?, ?)', [
                (int) $team->team_id,
                $team->earliest ?? '2026-01-01',
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('pulse_summary_pith_blocks')) {
            return;
        }

        if (Schema::hasColumn('pulse_summary_pith_blocks', 'shift_name')) {
            return;
        }

        Schema::table('pulse_summary_pith_blocks', function (Blueprint $table) {
            $table->string('shift_name')->nullable()->after('shift_date');
        });
    }
};
