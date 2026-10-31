<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Update EB_UNITS_PER_LOAD setting
        DB::table('pulse_settings')
            ->where('key', 'EB_UNITS_PER_LOAD')
            ->update([
                'text' => 'EB Units per CFT',
                'value' => '0.318',
                'updated_at' => now(),
            ]);

        // Update HUSKS_PER_LOAD setting
        DB::table('pulse_settings')
            ->where('key', 'HUSKS_PER_LOAD')
            ->update([
                'text' => 'Approx. CFT of Husks per CFT of Pith',
                'value' => '1.89',
                'updated_at' => now(),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert EB_UNITS_PER_LOAD setting
        DB::table('pulse_settings')
            ->where('key', 'EB_UNITS_PER_LOAD')
            ->update([
                'text' => 'EB units per load of pith',
                'value' => '0.32',
                'updated_at' => now(),
            ]);

        // Revert HUSKS_PER_LOAD setting
        DB::table('pulse_settings')
            ->where('key', 'HUSKS_PER_LOAD')
            ->update([
                'text' => 'Approx. CFT of Husks per Pith Load',
                'value' => '480',
                'updated_at' => now(),
            ]);
    }
};
