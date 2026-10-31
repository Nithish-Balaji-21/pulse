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
        if (Schema::hasTable('pulse_user_notification_preferences')) {
            return;
        }

        Schema::create('pulse_user_notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('team_id');
            $table->string('notification_type');
            $table->boolean('is_email_enabled')->default(false);
            $table->boolean('is_push_enabled')->default(false);
            $table->timestamps();

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();

            $table->foreign('team_id')
                ->references('id')
                ->on('teams')
                ->cascadeOnDelete();

            $table->unique(['user_id', 'team_id', 'notification_type'], 'pulse_user_team_notif_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pulse_user_notification_preferences');
    }
};
