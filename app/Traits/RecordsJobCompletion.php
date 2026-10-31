<?php

namespace App\Traits;

use App\Jobs\Middleware\ObserveCompilation;
use App\Jobs\Middleware\RecordJobRunCompletion;
use App\Services\Pulse\CompilationTelemetry;
use Throwable;

/**
 * Marks a device-data job as tracked in the `pulse_job_runs` ledger.
 *
 * Expects the host job to expose the `$teamId` and `$dates` properties shared by the
 * ProcessDeviceDataFor* jobs.
 */
trait RecordsJobCompletion
{
    public bool $scheduledCompilation = false;

    public function forScheduledCompilation(bool $scheduled): static
    {
        $this->scheduledCompilation = $scheduled;

        return $this;
    }

    public function compilationDeviceId(): int
    {
        return $this->deviceId;
    }

    public function failed(?Throwable $exception): void
    {
        app(CompilationTelemetry::class)->record($this, 'failed', error: $exception);
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new ObserveCompilation, new RecordJobRunCompletion];
    }

    public function ledgerJobName(): string
    {
        return class_basename(static::class);
    }

    public function ledgerTeamId(): int
    {
        return $this->teamId;
    }

    /**
     * The shift day this run is accountable for, or null for a multi-date backfill,
     * which has no ledger entry to close.
     */
    public function ledgerRunDate(): ?string
    {
        return count($this->dates) === 1 ? $this->dates[0] : null;
    }
}
