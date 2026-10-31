<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pulse_alerts_heartbeat_monitor', function (Blueprint $table) {
            $table->index('last_seen', 'idx_last_seen');
            $table->index('alert_sent', 'idx_alert_sent');
        });
    }

    public function down(): void
    {
        Schema::table('pulse_alerts_heartbeat_monitor', function (Blueprint $table) {
            $table->dropIndex('idx_last_seen');
            $table->dropIndex('idx_alert_sent');
        });
    }
};
