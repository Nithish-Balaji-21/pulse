<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('team_pulse', function (Blueprint $table) {
            $table->boolean('enable_notifications')->default(true)->after('modules');
            $table->renameColumn('license_valid', 'license_valid_till');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('team_pulse', function (Blueprint $table) {
            $table->renameColumn('license_valid_till', 'license_valid');
            $table->dropColumn('enable_notifications');
        });
    }
};
