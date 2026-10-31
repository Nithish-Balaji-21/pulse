<?php

namespace App\Traits;

/**
 * Retry policy for the nightly device-data jobs.
 *
 * These jobs become due within a short window of each other and write to the same
 * summary tables, so a failure is usually contention with a sibling job rather than
 * bad data. The queue default retries immediately, which walks straight back into
 * that contention, so each attempt waits — and the wait is jittered so two jobs that
 * collided once don't line up again on the retry.
 */
trait RetriesWithJitteredBackoff
{
    public int $tries = 3;

    /**
     * Seconds to wait before each retry.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [random_int(30, 90), random_int(120, 240)];
    }
}
