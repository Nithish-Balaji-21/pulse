<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The per-team colour customization feature is gone — every module now renders
 * from the file defaults in config/colors.php, so the override table has no
 * reader left. `down()` restores the empty table only; the stored overrides
 * themselves are not recoverable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('pulse_team_color_settings');
        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        Schema::create('pulse_team_color_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('team_id');
            $table->string('module', 50);
            $table->json('colors')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'module'], 'team_color_settings_team_id_module_unique');
            $table->index('module', 'team_color_settings_module_index');
            $table->foreign('team_id', 'team_color_settings_team_id_foreign')
                ->references('id')
                ->on('teams')
                ->cascadeOnDelete();
        });
    }
};
