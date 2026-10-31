<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var array<string, string>
     */
    private array $tables = [
        'alerts_device_health_log' => 'pulse_alerts_device_health_log',
        'alerts_heartbeat_monitor' => 'pulse_alerts_heartbeat_monitor',
        'device_data_cft_calc' => 'pulse_device_data_cft_calc',
        'device_data_vehicles_telemetry' => 'pulse_device_data_vehicles_telemetry',
    ];

    public function up(): void
    {
        foreach ($this->tables as $from => $to) {
            $this->renameIfPossible($from, $to);
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->tables) as $from => $to) {
            $this->renameIfPossible($to, $from);
        }
    }

    private function renameIfPossible(string $from, string $to): void
    {
        if (Schema::hasTable($from) && ! Schema::hasTable($to)) {
            Schema::rename($from, $to);
        }
    }
};
