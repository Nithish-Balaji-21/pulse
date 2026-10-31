<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Drops the legacy user_id column from teams.
     * Ownership is now fully managed through team_user.role = 'owner'.
     */
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            // Drop the index first, then the column
            $table->dropIndex('idx_user');
            $table->dropColumn('user_id');
        });
    }

    /**
     * Reverse the migrations.
     * Restores user_id from team_user owner row if present, otherwise defaults to 1.
     */
    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->unsignedInteger('user_id')->default(0)->after('id');
        });

        // Backfill user_id from owner role in team_user
        $owners = DB::table('team_user')->where('role', 'owner')->get()->keyBy('team_id');
        DB::table('teams')->get()->each(function ($team) use ($owners) {
            $userId = $owners[$team->id]->user_id ?? 0;
            DB::table('teams')->where('id', $team->id)->update(['user_id' => $userId]);
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->index('user_id', 'idx_user');
        });
    }
};
