<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

class SchedulerHeartbeat extends Command
{
    protected $signature = 'monitor:scheduler-heartbeat';

    protected $description = 'Pings the external monitor to confirm that Laravel scheduler is running';

    public function handle(): int
    {
        $url = config('services.scheduler_heartbeat.url');

        if (! is_string($url) || $url === '') {
            $this->info('Scheduler heartbeat is not configured.');

            return self::SUCCESS;
        }

        try {
            $response = Http::timeout((int) config('services.scheduler_heartbeat.timeout', 5))
                ->get($url);

            if (! $response->successful()) {
                $this->error('Scheduler heartbeat was rejected with HTTP '.$response->status().'.');

                return self::FAILURE;
            }
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Scheduler heartbeat could not be sent.');

            return self::FAILURE;
        }

        $this->info('Scheduler heartbeat sent.');

        return self::SUCCESS;
    }
}
