<?php

namespace App\Listeners;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Log;

class LogSlowQuery
{
    public function __construct(private readonly bool $isQueue = false) {}

    public function handle(QueryExecuted $query): void
    {
        $threshold = (float) config('logging.channels.slow_queries.threshold_ms', 1000);

        if ($query->time <= $threshold) {
            return;
        }

        // Do not send statement text or bindings to Nightwatch: they can contain user data.
        Log::stack(['slow_queries', 'nightwatch'])->alert('Slow database query', [
            'duration_ms' => round($query->time, 1),
            'threshold_ms' => $threshold,
            'connection' => $query->connectionName,
            'source' => $this->isQueue ? 'queue' : (app()->runningInConsole() ? 'console' : 'web'),
            'query_fingerprint' => hash('sha256', preg_replace('/\s+/', ' ', trim($query->sql))),
        ]);
    }
}
