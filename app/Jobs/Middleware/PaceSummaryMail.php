<?php

namespace App\Jobs\Middleware;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class PaceSummaryMail
{
    public function handle(object $job, Closure $next): void
    {
        $mailer = $job->mailable->mailer ?: config('mail.default');
        $account = config("mail.mailers.{$mailer}.host").'|'.config("mail.mailers.{$mailer}.username");
        $key = 'summary-mail:'.hash('sha256', $account);
        $cache = Cache::store(config('mail_delivery.store'));
        $lock = $cache->lock($key.':lock', 120);

        if (! $lock->get()) {
            $job->release(60);

            return;
        }

        try {
            $wait = max(
                (int) $cache->get($key.':blocked', 0),
                (int) $cache->get($key.':next', 0),
            ) - now()->timestamp;

            if ($wait > 0) {
                $job->release($wait);

                return;
            }

            // Count attempts as well as successes so failures cannot create a burst.
            $interval = max(1, (int) config('mail_delivery.interval_seconds', 60));
            $cache->put($key.':next', now()->timestamp + $interval, $interval);

            try {
                $next($job);
            } catch (TransportExceptionInterface $exception) {
                if (! preg_match('/550\s+5\.4\.6\b/', $exception->getMessage())) {
                    throw $exception;
                }

                $cooldown = max(60, (int) config('mail_delivery.blocked_seconds', 3600));
                $cache->put($key.':blocked', now()->timestamp + $cooldown, $cooldown);
                Log::warning('Zoho blocked summary mail; queued delivery deferred.', [
                    'cooldown_seconds' => $cooldown,
                ]);
                // Keep the email queued; never sleep a worker or retry a blocked account immediately.
                $job->release($cooldown);
            }
        } finally {
            $lock->release();
        }
    }
}
