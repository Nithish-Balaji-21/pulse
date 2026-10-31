<?php

namespace App\Jobs\Middleware;

use App\Services\Pulse\CompilationTelemetry;
use Closure;
use Throwable;

class ObserveCompilation
{
    public function handle(object $job, Closure $next): mixed
    {
        $monitor = app(CompilationTelemetry::class);
        $started = microtime(true);
        $monitor->record($job, 'started');
        try {
            $result = $next($job);
        } catch (Throwable $error) {
            $monitor->record($job, 'error', microtime(true) - $started, $error);
            throw $error;
        }

        if ($job->job?->hasFailed()) {
            // Laravel invokes failed() for final/manual failures; don't send twice.
            return $result;
        }
        $monitor->record($job, $job->job?->isReleased() ? 'released' : 'succeeded', microtime(true) - $started);

        return $result;
    }
}
