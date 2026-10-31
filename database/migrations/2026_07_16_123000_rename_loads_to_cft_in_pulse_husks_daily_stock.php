<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Drop trigger temporarily so it doesn't break during column rename/update
        DB::unprepared('DROP TRIGGER IF EXISTS `trg_reconcile_before_update_stock`');

        if (Schema::hasColumn('pulse_husks_daily_stock', 'loads')) {
            Schema::table('pulse_husks_daily_stock', function (Blueprint $table) {
                $table->renameColumn('loads', 'cft');
            });
        }

        // Multiply loads (now cft) by 300 to convert to CFT for entries before July 2, 2026
        DB::table('pulse_husks_daily_stock')
            ->where('date', '<', '2026-07-02')
            ->update([
                'cft' => DB::raw('cft * 300'),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('pulse_husks_daily_stock', 'cft')) {
            // Revert CFT to loads for entries before July 2, 2026
            DB::table('pulse_husks_daily_stock')
                ->where('date', '<', '2026-07-02')
                ->update([
                    'cft' => DB::raw('cft / 300'),
                ]);

            Schema::table('pulse_husks_daily_stock', function (Blueprint $table) {
                $table->renameColumn('cft', 'loads');
            });
        }
    }
};
