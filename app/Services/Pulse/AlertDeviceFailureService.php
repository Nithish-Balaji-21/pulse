<?php

namespace App\Services\Pulse;

use App\Console\Commands\Pulse\CheckDeviceHealthAlerts;
use App\Console\Commands\Pulse\CheckHeartbeatAlerts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class AlertDeviceFailureService
{
    /**
     * Run device health log checks and heartbeat monitor checks (emails where applicable).
     */
    public function run(): int
    {
        $exit = Command::SUCCESS;

        try {
            $code = app(CheckDeviceHealthAlerts::class)->handle();
            if ($code !== Command::SUCCESS) {
                Log::error("AlertDeviceFailureService: device health check exited with code {$code}");
                $exit = Command::FAILURE;
            }
        } catch (\Throwable $e) {
            Log::error('AlertDeviceFailureService: device health check failed: '.$e->getMessage());
            $exit = Command::FAILURE;
        }

        try {
            $code = app(CheckHeartbeatAlerts::class)->handle();
            if ($code !== Command::SUCCESS) {
                Log::error("AlertDeviceFailureService: heartbeat check exited with code {$code}");
                $exit = Command::FAILURE;
            }
        } catch (\Throwable $e) {
            Log::error('AlertDeviceFailureService: heartbeat check failed: '.$e->getMessage());
            $exit = Command::FAILURE;
        }

        return $exit;
    }
}
