<?php

namespace App\Http\Middleware;

use App\Notifications\ApiPerformanceAlert;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

class LogSlowApiRequest
{
    /** @var (Closure(): int)|null */
    private readonly ?Closure $clock;

    public function __construct(?Closure $clock = null)
    {
        $this->clock = $clock;
    }

    public function handle(Request $request, Closure $next): mixed
    {
        if (! config('api-performance.enabled')) {
            return $next($request);
        }

        $startedAt = $this->nowNanoseconds();
        $response = null;

        try {
            $response = $next($request);

            return $response;
        } finally {
            $durationMs = round(($this->nowNanoseconds() - $startedAt) / 1_000_000, 1);
            $this->record($request, $durationMs, $response?->getStatusCode() ?? 500);
        }
    }

    private function record(Request $request, float $durationMs, int $status): void
    {
        $warningMs = (int) config('api-performance.warning_ms');
        $alertMs = max($warningMs, (int) config('api-performance.alert_ms'));

        if ($durationMs <= $warningMs) {
            return;
        }

        $level = $durationMs > $alertMs ? 'alert' : 'warning';
        $context = [
            'endpoint' => $request->route()?->uri() ?? $request->path(),
            'method' => $request->method(),
            'status' => $status,
            'duration_ms' => $durationMs,
            'warning_threshold_ms' => $warningMs,
            'alert_threshold_ms' => $alertMs,
            'environment' => app()->environment(),
            'server' => config('nightwatch.server'),
        ];

        try {
            Log::channel('api_performance')->log($level, 'Slow API response', $context);

            if ($level === 'alert' && filter_var(config('api-performance.email'), FILTER_VALIDATE_EMAIL)) {
                Notification::route('mail', config('api-performance.email'))
                    ->notify(new ApiPerformanceAlert($context));
            }
        } catch (Throwable $monitoringError) {
            // Slow-request telemetry must never change the API response.
            Log::channel('single')->error('API performance monitoring could not record an event', [
                'exception_class' => $monitoringError::class,
            ]);
        }
    }

    private function nowNanoseconds(): int
    {
        return $this->clock ? ($this->clock)() : hrtime(true);
    }
}
