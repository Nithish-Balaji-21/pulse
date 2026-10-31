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
        // uniq_team_staff (team_id, staff_name) was created outside migrations and left
        // INVISIBLE — the optimizer can't use it for query planning even though it still
        // enforces the uniqueness constraint. Making it visible restores its query value.
        DB::statement('ALTER TABLE staffs ALTER INDEX uniq_team_staff VISIBLE');

        Schema::table('pulse_projects', function (Blueprint $table) {
            $table->index('team_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pulse_projects', function (Blueprint $table) {
            $table->dropIndex(['team_id']);
        });

        DB::statement('ALTER TABLE staffs ALTER INDEX uniq_team_staff INVISIBLE');
    }
};
