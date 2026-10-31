<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pulse_pith_location_groups')) {
            Schema::create('pulse_pith_location_groups', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('team_id');
                $table->string('name', 50);
                $table->unsignedInteger('display_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['team_id', 'name'], 'uniq_team_location_group');
                $table->foreign('team_id')->references('id')->on('teams')->cascadeOnDelete();
            });
        }

        if (! Schema::hasColumn('pulse_pith_locations', 'group_id')) {
            Schema::table('pulse_pith_locations', function (Blueprint $table) {
                $table->unsignedInteger('group_id')->nullable()->after('team_id');

                $table->foreign('group_id')
                    ->references('id')
                    ->on('pulse_pith_location_groups')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('pulse_pith_locations', 'group_id')) {
            Schema::table('pulse_pith_locations', function (Blueprint $table) {
                $table->dropForeign(['group_id']);
                $table->dropColumn('group_id');
            });
        }

        Schema::dropIfExists('pulse_pith_location_groups');
    }
};
