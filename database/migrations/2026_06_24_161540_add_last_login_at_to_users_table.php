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
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_login_at')->nullable()->after('is_active');
        });

        // Backfill existing users with updated_at as their last_login_at.
        // updated_at is a better approximation of last activity than created_at.
        // This ensures existing users are not treated as new users by the JS tour check
        // (lastLoggedInAt will be non-null, so the tour will be skipped for them).
        DB::table('users')->update([
            'last_login_at' => DB::raw('updated_at'),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('last_login_at');
        });
    }
};
