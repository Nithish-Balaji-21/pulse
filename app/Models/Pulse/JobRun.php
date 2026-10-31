<?php

namespace App\Models\Pulse;

use App\Models\Team;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-team execution ledger for the nightly device-data jobs.
 *
 * A row is written when a job is dispatched for a team and stamped with
 * `completed_at` by the job itself, so `system:check_errors` can tell the
 * difference between "the command ran" and "this team was actually processed".
 */
class JobRun extends Model
{
    protected $table = 'pulse_job_runs';

    protected $fillable = [
        'job_name',
        'team_id',
        'run_date',
        'dispatched_at',
        'completed_at',
    ];

    public $timestamps = true;

    protected function casts(): array
    {
        return [
            'run_date' => 'date',
            'dispatched_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Open a ledger entry for a job about to be dispatched.
     *
     * Re-dispatching the same team/date clears any previous `completed_at` so the
     * row always reflects the most recent attempt rather than a stale success.
     */
    public static function recordDispatch(string $jobName, int $teamId, string $runDate): void
    {
        static::updateOrCreate(
            ['job_name' => $jobName, 'team_id' => $teamId, 'run_date' => $runDate],
            ['dispatched_at' => Carbon::now(), 'completed_at' => null],
        );
    }

    /**
     * Close the ledger entry for a job that finished successfully.
     *
     * Manual backfills dispatch without opening a row, so this is a no-op when
     * no matching entry exists.
     */
    public static function recordCompletion(string $jobName, int $teamId, string $runDate): void
    {
        static::where('job_name', $jobName)
            ->where('team_id', $teamId)
            ->where('run_date', $runDate)
            ->update(['completed_at' => Carbon::now()]);
    }

    /**
     * Entries from the given date onward that were dispatched but never completed.
     *
     * Spans several shift days rather than one, so a team whose job is delayed past the
     * checker's own slot is still examined on a later run instead of dropping out of
     * scope the moment its shift day stops being yesterday.
     *
     * @param  array<int, string>  $jobNames
     */
    public static function incompleteSince(string $earliestRunDate, array $jobNames = []): Collection
    {
        return static::query()
            ->where('run_date', '>=', $earliestRunDate)
            ->whereNull('completed_at')
            ->when($jobNames, fn ($query) => $query->whereIn('job_name', $jobNames))
            ->orderBy('run_date')
            ->get();
    }

    /**
     * Entries for the given date that were dispatched but never completed.
     *
     * @param  array<int, string>  $jobNames
     */
    public static function incompleteOn(string $runDate, array $jobNames = []): Collection
    {
        return static::query()
            ->where('run_date', $runDate)
            ->whereNull('completed_at')
            ->when($jobNames, fn ($query) => $query->whereIn('job_name', $jobNames))
            ->get();
    }
}
