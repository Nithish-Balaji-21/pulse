<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Universal multiplier for all teams
        $cftPerLoad = 300;

        DB::transaction(function () use ($cftPerLoad) {

            // ----------------------------------------------------
            // 1. INWARD: Convert Pith Productions (loads_produced, loads_expected)
            // ----------------------------------------------------
            $productions = DB::table('pulse_summary_pith_productions')->get();
            $prodUpdated = 0;

            foreach ($productions as $prod) {
                // If it's already converted, we might not want to re-convert, but assuming all historical is old
                DB::table('pulse_summary_pith_productions')
                    ->where('id', $prod->id)
                    ->update([
                        'loads_produced' => DB::raw("loads_produced * {$cftPerLoad}"),
                        'loads_expected' => DB::raw("loads_expected * {$cftPerLoad}"),
                    ]);
                $prodUpdated++;
            }
            Log::info("Migrated {$prodUpdated} inward production records to CFT.");

            // ----------------------------------------------------
            // 2. INWARD: Convert Pith Loads Offloaded
            // ----------------------------------------------------
            $inwardLoads = DB::table('pulse_pith_loads')
                ->whereNotNull('loads_offloaded')
                ->get();

            $inwardUpdated = 0;

            foreach ($inwardLoads as $load) {
                DB::table('pulse_pith_loads')
                    ->where('id', $load->id)
                    ->update([
                        'loads_offloaded' => $load->loads_offloaded * $cftPerLoad,
                    ]);
                $inwardUpdated++;
            }
            Log::info("Migrated {$inwardUpdated} inward offloaded records to CFT.");

            // ----------------------------------------------------
            // 3. OUTWARD: Convert Pith Loads Taken
            // ----------------------------------------------------
            $outwardLoads = DB::table('pulse_pith_loads')
                ->whereNotNull('taken_unit_id')
                ->whereNotNull('taken_unit_qty')
                ->whereNotNull('taken_unit_load_ratio')
                ->where('taken_unit_load_ratio', '>', 0)
                ->get();

            $outwardUpdated = 0;

            foreach ($outwardLoads as $load) {
                // Example: 55 bags / 55 load_ratio = 1 load
                $calculatedLoads = $load->taken_unit_qty / $load->taken_unit_load_ratio;

                // Example: 1 load * 300 = 300 CFT
                $takenCft = $calculatedLoads * $cftPerLoad;

                DB::table('pulse_pith_loads')
                    ->where('id', $load->id)
                    ->update([
                        'taken_loads_calculated' => $takenCft,
                    ]);

                $outwardUpdated++;
            }
            Log::info("Migrated {$outwardUpdated} historical outward records to CFT.");

            // ----------------------------------------------------
            // 4. DEPLETIONS: Convert Pith Stock Depletions
            // ----------------------------------------------------
            $depletionsUpdated = 0;
            $depletions = DB::table('pulse_pith_stock_depletions')->get();
            foreach ($depletions as $depletion) {
                DB::table('pulse_pith_stock_depletions')
                    ->where('id', $depletion->id)
                    ->update([
                        'total_loads_offloaded' => DB::raw("total_loads_offloaded * {$cftPerLoad}"),
                        'total_loads_taken_calculated' => DB::raw("total_loads_taken_calculated * {$cftPerLoad}"),
                    ]);
                $depletionsUpdated++;
            }
            Log::info("Migrated {$depletionsUpdated} stock depletion records to CFT.");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $cftPerLoad = 300;

        DB::transaction(function () use ($cftPerLoad) {

            // Revert Productions
            DB::table('pulse_summary_pith_productions')
                ->update([
                    'loads_produced' => DB::raw("loads_produced / {$cftPerLoad}"),
                    'loads_expected' => DB::raw("loads_expected / {$cftPerLoad}"),
                ]);

            // Revert Pith Loads
            DB::table('pulse_pith_loads')
                ->update([
                    'loads_offloaded' => DB::raw("loads_offloaded / {$cftPerLoad}"),
                ]);

            $loads = DB::table('pulse_pith_loads')
                ->whereNotNull('taken_unit_id')
                ->get();

            foreach ($loads as $load) {
                $revertedLoads = $load->taken_loads_calculated / $cftPerLoad;

                DB::table('pulse_pith_loads')
                    ->where('id', $load->id)
                    ->update([
                        'taken_loads_calculated' => $revertedLoads,
                    ]);
            }
        });
    }
};
