<?php

namespace App\Jobs\Middleware;

use App\Models\Pulse\JobRun;
use Closure;

/**
 * Closes the `pulse_job_runs` ledger entry once a device-data job finishes.
 *
 * Running as job middleware rather than a call at the end of `handle()` means every
 * successful path is covered, including the early returns taken when a team has no
 * shifts or no new device data. A job that throws never reaches this, so the entry
 * correctly stays open and trips the missed-run alert.
 */
class RecordJobRunCompletion
{
    public function handle(object $job, Closure $next): mixed
    {
        $result = $next($job);

        $runDate = $job->ledgerRunDate();

        if ($runDate !== null && ! ($job->job?->isReleased() ?? false) && ! ($job->job?->hasFailed() ?? false)) {
            JobRun::recordCompletion($job->ledgerJobName(), $job->ledgerTeamId(), $runDate);
        }

        return $result;
    }
}
