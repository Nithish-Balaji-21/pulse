<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mobile health + full-trail geofence debug logs from every staff phone.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pulse_geofence_device_logs')
            && ! Schema::hasTable('pulse_geofence_mobile_logs')) {
            Schema::rename('pulse_geofence_device_logs', 'pulse_geofence_mobile_logs');

            return;
        }

        if (Schema::hasTable('pulse_geofence_mobile_logs')) {
            return;
        }

        Schema::create('pulse_geofence_mobile_logs', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('team_id');
            $table->string('step', 40);
            $table->string('category', 60);
            $table->string('reason', 120)->nullable();
            $table->string('geofence_id', 40)->nullable();
            $table->string('event_type', 10)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedTinyInteger('battery_percent')->nullable();
            $table->boolean('battery_optimization_ignored')->nullable();
            $table->boolean('gps_enabled')->nullable();
            $table->string('location_permission', 40)->nullable();
            $table->string('background_location_permission', 40)->nullable();
            $table->string('device_model', 120)->nullable();
            $table->string('staff_label', 120)->nullable();
            $table->boolean('trackable')->default(true);
            $table->boolean('code_slow')->default(false);
            $table->boolean('device_slow')->default(false);
            $table->boolean('os_slow')->default(false);
            $table->json('extra')->nullable();
            $table->dateTime('recorded_at');
            $table->dateTime('received_at');
            $table->timestamps();

            $table->index(['team_id', 'recorded_at'], 'idx_geofence_mobile_logs_team_time');
            $table->index(['team_id', 'user_id', 'recorded_at'], 'idx_geofence_mobile_logs_team_user_time');
            $table->index(['team_id', 'reason'], 'idx_geofence_mobile_logs_team_reason');

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('team_id')->references('id')->on('teams')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pulse_geofence_mobile_logs');
        Schema::dropIfExists('pulse_geofence_device_logs');
    }
};
