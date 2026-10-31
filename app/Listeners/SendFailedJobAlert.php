<?php

namespace App\Listeners;

use App\Models\User;
use App\Notifications\QueueJobFailedAlert;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

class SendFailedJobAlert
{
    public function __invoke(JobFailed $event): void
    {
        if (! config('monitoring.failed_job_alerts.enabled')) {
            return;
        }

        $recipients = config('monitoring.failed_job_alerts.recipients', []);

        if ($recipients === []) {
            $recipients = User::query()
                ->where('is_superadmin', 1)
                ->where('is_active', 1)
                ->whereNotNull('email')
                ->where('email', '!=', '')
                ->pluck('email')
                ->unique()
                ->values()
                ->all();
        }

        if ($recipients === []) {
            Log::warning('Queue job failed, but no failed-job alert recipients or active superadmins are configured.');

            return;
        }

        $details = [
            'job' => $event->job->resolveName(),
            'job_id' => (string) $event->job->getJobId(),
            'connection' => $event->connectionName,
            'queue' => $event->job->getQueue(),
            'attempts' => $event->job->attempts(),
            'exception' => $this->exceptionSummary($event->exception),
            'occurred_at' => now()->toIso8601String(),
            'environment' => app()->environment(),
            'server' => (string) config('nightwatch.server', gethostname()),
        ];

        Log::alert('Queue job failed permanently', $details);

        try {
            Notification::route('mail', $recipients)
                ->notifyNow(new QueueJobFailedAlert($details));
        } catch (Throwable $exception) {
            // Do not let a mail-provider failure interfere with Horizon's failed-job handling.
            Log::error('Failed to send the queue-job failure alert.', [
                'job_id' => $details['job_id'],
                'exception' => $exception::class,
            ]);
        }
    }

    private function exceptionSummary(Throwable $exception): string
    {
        return str($exception->getMessage())
            ->replaceMatches('/\s+/', ' ')
            ->limit(500)
            ->prepend($exception::class.': ')
            ->toString();
    }
}
