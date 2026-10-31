<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The nightly pith job clears a shift day with
 * `where device_id = ? and start_time between ? and ?`, but the only usable index was
 * `idx_device_date_state (device_id, date, state)`. With no `start_time` component the
 * planner either scanned every row for the device or fell back to a full scan of
 * PRIMARY, taking next-key locks on rows belonging to other teams. Teams sharing a
 * shift anchor run that delete concurrently, which deadlocked them against each other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pulse_summary_power_states', function (Blueprint $table) {
            $table->index(['device_id', 'start_time'], 'idx_device_start_time');
        });
    }

    public function down(): void
    {
        Schema::table('pulse_summary_power_states', function (Blueprint $table) {
            $table->dropIndex('idx_device_start_time');
        });
    }
};
