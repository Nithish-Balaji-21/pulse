<?php

namespace App\Console\Commands\Pulse;

use App\Services\Pulse\AlertDeviceFailureService;
use Illuminate\Console\Command;

class AlertDeviceFailureCommand extends Command
{
    protected $signature = 'alerts:device-failure';

    protected $description = 'Run device health and heartbeat failure checks (technical alerts)';

    public function handle(AlertDeviceFailureService $alerts): int
    {
        return $alerts->run();
    }
}
