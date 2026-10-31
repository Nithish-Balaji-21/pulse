<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A NULL is_superadmin value used to crash User::isSuperadmin() (formerly a same-named
 * property/method collision) because isset() treats NULL as "attribute missing", sending
 * Eloquent down the relationship-resolution path instead of returning the column. The method
 * has been renamed to remove that collision permanently; this backfills any existing NULL
 * rows and makes the column NOT NULL so it can't happen again from this column at least.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->whereNull('is_superadmin')->update(['is_superadmin' => 0]);

        Schema::table('users', function (Blueprint $table) {
            $table->tinyInteger('is_superadmin')->default(0)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->tinyInteger('is_superadmin')->default(0)->nullable()->change();
        });
    }
};
