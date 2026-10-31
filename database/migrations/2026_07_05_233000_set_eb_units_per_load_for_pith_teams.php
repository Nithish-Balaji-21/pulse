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
        DB::table('pulse_settings')
            ->where('module', 'pith')
            ->where('key', 'EB_UNITS_PER_LOAD')
            ->whereIn('team_id', function ($query) {
                $query->select('id')
                    ->from('teams');
            })
            ->update([
                'value' => '0.32',
                'updated_at' => now(),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('pulse_settings')
            ->where('module', 'pith')
            ->where('key', 'EB_UNITS_PER_LOAD')
            ->whereIn('team_id', function ($query) {
                $query->select('id')
                    ->from('teams');
            })
            ->update([
                'value' => '94.11',
                'updated_at' => now(),
            ]);
    }
};
