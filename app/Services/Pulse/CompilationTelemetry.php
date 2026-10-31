<?php

namespace App\Services\Pulse;

use App\Notifications\CompilationOutcome;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Laravel\Nightwatch\Facades\Nightwatch;
use Throwable;

class CompilationTelemetry
{
    public function record(object $job, string $status, ?float $duration = null, ?Throwable $error = null): void
    {
        // Observability must never turn a completed compilation into a retry.
        try {
            if (in_array($status, ['error', 'failed'], true)) {
                Nightwatch::sample(rate: 1.0);
            }
            $context = [
                'factory_id' => $job->ledgerTeamId(),
                'device_id' => $job->compilationDeviceId(),
                'production_date' => $job->ledgerRunDate(),
                'job' => class_basename($job),
                'job_id' => $job->job?->uuid(),
                'attempt' => $job->job?->attempts() ?? 1,
                'status' => $status,
                'recorded_at' => now()->toIso8601String(),
                'duration_seconds' => $duration === null ? null : round($duration, 3),
                'exception_class' => $error ? $error::class : null,
                'exception_file' => $error?->getFile(),
                'exception_line' => $error?->getLine(),
                'scheduled' => $job->scheduledCompilation,
                'environment' => app()->environment(),
                'server' => config('nightwatch.server'),
            ];
            Log::channel('compilation')->log(in_array($status, ['error', 'failed']) ? 'error' : 'info', '[compilation] '.$status, $context);

            if ($job->scheduledCompilation && in_array($status, ['succeeded', 'failed'])
                && config('compilation-notifications.enabled')
                && filter_var(config('compilation-notifications.email'), FILTER_VALIDATE_EMAIL)) {
                Notification::route('mail', config('compilation-notifications.email'))
                    ->notify(new CompilationOutcome($context));
            }
        } catch (Throwable $monitorError) {
            // Preserve the business outcome even if logging or the alert queue fails.
            try {
                Log::channel('single')->error('Compilation monitoring could not record or queue an alert', [
                    'status' => $status, 'exception_class' => $monitorError::class,
                ]);
            } catch (Throwable) {
                error_log('Compilation monitoring unavailable; business outcome preserved.');
            }
        }
    }
}
