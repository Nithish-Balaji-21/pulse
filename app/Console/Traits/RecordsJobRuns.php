<?php

namespace App\Console\Traits;

use App\Models\Pulse\JobRun;

/**
 * Opens a `pulse_job_runs` ledger entry for each job a scheduled command dispatches.
 *
 * Only the unattended nightly path is tracked. Manual backfills (`--dates`,
 * `--start`/`--end`, `--team_ids`) are re-runs rather than the nightly guarantee,
 * so they neither open ledger rows nor trip the missed-run alert.
 */
trait RecordsJobRuns
{
    /**
     * Options that narrow a run to a subset of teams, dates or modules. Any of them
     * being set means this is a targeted re-run, not the nightly sweep.
     *
     * @var array<int, string>
     */
    protected array $manualRunOptions = ['dates', 'start', 'end', 'team_ids', 'modules'];

    /**
     * Whether this invocation is the unattended scheduled run rather than a manual backfill.
     */
    protected function isScheduledRun(): bool
    {
        foreach ($this->manualRunOptions as $option) {
            if ($this->getDefinition()->hasOption($option) && ! empty($this->option($option))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Open a ledger entry for a dispatched job, keyed on the job class rather than
     * the command name so commands dispatching several jobs per team stay distinct.
     *
     * @param  class-string  $jobClass
     * @param  array<int, string>  $dates
     */
    protected function recordDispatch(string $jobClass, int $teamId, array $dates): void
    {
        if (! $this->isScheduledRun() || count($dates) !== 1) {
            return;
        }

        JobRun::recordDispatch(class_basename($jobClass), $teamId, $dates[0]);
    }
}
