<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Records on each pith inventory row the closure (pulse_pith_stock_depletions row) that
 * archived it, so a location's batches can be told apart without guessing from dates — the
 * same link pulse_pith_waterings already carries.
 *
 * Backfill: each depletion claims the archived rows at its location inside its
 * first/last inventory id range. Locations merged on 2026-07-29 carry batches whose id
 * ranges overlap; walking the closures oldest first means the earliest closure wins those
 * rows, a best guess since the merge kept no record of where they came from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pulse_pith_inventory', function (Blueprint $table) {
            $table->unsignedInteger('pith_stock_depletion_id')->nullable()->after('is_archived');

            $table->index(['pith_location_id', 'pith_stock_depletion_id'], 'idx_pith_inventory_location_depletion');
        });

        $depletions = DB::table('pulse_pith_stock_depletions')
            ->whereNotNull('first_pith_inventory_id')
            ->whereNotNull('last_pith_inventory_id')
            ->orderBy('depletion_date')
            ->orderBy('id')
            ->get(['id', 'pith_location_id', 'first_pith_inventory_id', 'last_pith_inventory_id']);

        foreach ($depletions as $depletion) {
            DB::table('pulse_pith_inventory')
                ->where('pith_location_id', $depletion->pith_location_id)
                ->whereBetween('id', [$depletion->first_pith_inventory_id, $depletion->last_pith_inventory_id])
                ->where('is_archived', 1)
                ->whereNull('pith_stock_depletion_id')
                ->update(['pith_stock_depletion_id' => $depletion->id]);
        }

        $unassigned = DB::table('pulse_pith_inventory')
            ->where('is_archived', 1)
            ->whereNull('pith_stock_depletion_id')
            ->count();

        if ($unassigned > 0) {
            Log::warning('Archived pith inventory rows left without a closure after backfill', ['count' => $unassigned]);
        }
    }

    public function down(): void
    {
        Schema::table('pulse_pith_inventory', function (Blueprint $table) {
            $table->dropIndex('idx_pith_inventory_location_depletion');
            $table->dropColumn('pith_stock_depletion_id');
        });
    }
};
