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
        DB::table('pulse_summary_pith_productions')
            ->where('team_id', 8)
            ->where('shift_name', 'Day Shift')
            ->update(['shift_name' => 'Shift 1']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('pulse_summary_pith_productions')
            ->where('team_id', 8)
            ->where('shift_name', 'Shift 1')
            ->where('start_time', 'like', '%08:00:00')
            ->where('end_time', 'like', '%18:30:00')
            ->update(['shift_name' => 'Day Shift']);
    }
};
