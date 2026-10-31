<?php

namespace App\Console\Commands;

use App\Models\Pulse\JobRun;
use App\Models\Pulse\Shift;
use App\Models\Team;
use App\Models\User;
use App\Notifications\CronMissedNotification;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class CheckSystemErrorsCron extends Command
{
    protected $signature = 'system:check_errors';

    protected $description = 'Check the nightly device-data pipeline for missed commands, failed queue jobs and teams that were dispatched but never processed.';

    /**
     * Shift days of ledger history to judge, counting back from yesterday.
     *
     * A team anchored later than this command's own slot is not yet due when its shift
     * day first comes up, so one extra day of history gives it a second look rather
     * than letting it fall out of scope unexamined. It also bounds how long a genuinely
     * stuck entry keeps alerting.
     */
    protected const LEDGER_LOOKBACK_DAYS = 2;

    /**
     * Slack allowed for a job to actually run once its delay has elapsed. Jobs finish in
     * single-digit seconds; this only absorbs queue backlog so a busy night does not read
     * as a missed run.
     */
    protected const EXECUTION_GRACE_MINS = 15;

    /**
     * An unchanged outstanding issue is logged on every run, but the email alert is
     * sent at most once per hour. A new or changed issue has a new fingerprint and
     * notifies immediately.
     */
    protected const ALERT_COOLDOWN_MINS = 60;

    public function handle()
    {
        $missedCrons = [];
        $failedJobsGrouped = [];
        $unprocessedTeams = [];

        $today = Carbon::today();
        $expectedJobs = [
            'devicedata:pith',
            'devicedata:fibre',
            'devicedata:growbags',
            'devicedata:others',
        ];

        // Nothing this command checks can be judged before the nightly commands have had
        // their chance to dispatch, so the whole run is held rather than just the
        // missed-cron block: a ledger entry left open from an earlier day would otherwise
        // still email the superadmins at midnight.
        $commandsDueAt = $this->deviceCommandsDueAt($today);

        if (Carbon::now()->lessThan($commandsDueAt)) {
            Log::info('system:check_errors: Nothing is due for judgement until '.$commandsDueAt->toDateTimeString().'; skipping this run.');

            return;
        }

        foreach ($expectedJobs as $jobName) {
            $cronRan = DB::table('cron_runs')
                ->where('job_name', $jobName)
                ->where('run_date', $today)
                ->exists();

            if (! $cronRan) {
                Log::error("CRON MISSED: {$jobName} did not run on {$today->toDateString()}.");
                $missedCrons[] = [
                    'job_name' => $jobName,
                    'date' => $today->toDateString(),
                ];
            }
        }

        // Check the per-team ledger for jobs that were dispatched but never completed.
        // `cron_runs` above only proves a command ran; this proves each team was processed.
        // Each job is delayed from dispatch to its team's shift anchor, so an open entry
        // means nothing until that delay has elapsed — see isNotYetDue().
        $earliestRunDate = Carbon::yesterday()->subDays(self::LEDGER_LOOKBACK_DAYS - 1)->toDateString();

        foreach (JobRun::incompleteSince($earliestRunDate) as $run) {
            $runDate = $run->run_date->toDateString();

            if ($this->isNotYetDue($run)) {
                Log::info("TEAM NOT DUE: {$run->job_name} for team {$run->team_id} is delayed beyond this check; shift day {$runDate} will be judged on the next run.");

                continue;
            }

            $teamName = optional(Team::find($run->team_id))->name ?? "Team {$run->team_id}";

            Log::error("TEAM NOT PROCESSED: {$run->job_name} for {$teamName} was dispatched at {$run->dispatched_at} but never completed for shift day {$runDate}.");

            $unprocessedTeams[$teamName][] = [
                'job_name' => $run->job_name,
                'run_date' => $runDate,
            ];
        }

        // Check for failed queued jobs today
        $failedJobs = DB::table('failed_jobs')
            ->whereDate('failed_at', $today)
            ->get();

        foreach ($failedJobs as $failedJob) {
            $payload = json_decode($failedJob->payload, true);
            $jobName = $payload['displayName'] ?? 'Unknown Job';
            $teamName = null;

            if (! empty($payload['data']['command']) && ($job = @unserialize($payload['data']['command']))) {
                if (method_exists($job, 'getTeamId')) {
                    $teamId = $job->getTeamId();
                } elseif (property_exists($job, 'teamId')) {
                    $teamId = $job->teamId;
                } elseif (property_exists($job, 'mailable') && method_exists($job->mailable, 'getTeamId')) {
                    // Special case for queued Mailables
                    $teamId = $job->mailable->getTeamId();
                } else {
                    $teamId = null;
                }

                $teamName = $teamId ? optional(Team::find($teamId))->name : null;
            }

            Log::error("QUEUE FAILED: Job {$jobName} failed at {$failedJob->failed_at}".($teamName ? " (Team: {$teamName})" : ''));

            $teamKey = $teamName ?? 'Unknown Team';
            $failedJobsGrouped[$teamKey][] = class_basename($jobName);
        }

        foreach ($failedJobsGrouped as $team => $jobs) {
            $failedJobsGrouped[$team] = array_unique($jobs);
        }

        if ($missedCrons || $failedJobsGrouped || $unprocessedTeams) {
            if ((app()->environment('local') || app()->environment('demo')) && (int) env('APP_SEND_EMAIL', 0) !== 1) {
                Log::info('system:check_errors: Skipping notification in local/demo environment.');

                return;
            }

            $fingerprint = hash('sha256', json_encode([
                $missedCrons,
                $failedJobsGrouped,
                $unprocessedTeams,
            ], JSON_INVALID_UTF8_SUBSTITUTE));

            if (! Cache::add("system:check_errors:{$fingerprint}", true, now()->addMinutes(self::ALERT_COOLDOWN_MINS))) {
                Log::info('system:check_errors: Identical alert is within its notification cooldown.');

                return;
            }

            $superAdmins = User::where('is_superadmin', 1)->get();
            Notification::send($superAdmins, new CronMissedNotification($missedCrons, $failedJobsGrouped, $today, $unprocessedTeams));
        }
    }

    /**
     * Fallbacks used only when the config keys are unreadable.
     *
     * `routes/console.php` resolves the nightly slot while the console kernel boots, so a
     * null there throws before any command can run — including the config:clear that would
     * fix it. A deploy that pulls new config keys against a stale config cache hit exactly
     * that and took the whole scheduler down. These keep the schedule loading with the
     * shipped defaults instead; config/app.php remains the source of truth.
     */
    public const FALLBACK_DEVICE_CRON_AT = '00:10';

    public const FALLBACK_START_GRACE_MINS = 15;

    /**
     * The configured slot for the nightly devicedata:* commands, as `H:i`.
     */
    public static function deviceCronAt(): string
    {
        $configured = config('app.daily_device_cron_at');

        return is_string($configured) && $configured !== ''
            ? $configured
            : self::FALLBACK_DEVICE_CRON_AT;
    }

    /**
     * Minutes allowed after that slot before a missing dispatch counts as a missed run.
     */
    public static function deviceCronStartGraceMins(): int
    {
        $configured = config('app.daily_device_cron_start_grace_mins');

        return is_numeric($configured) ? (int) $configured : self::FALLBACK_START_GRACE_MINS;
    }

    /**
     * The earliest moment in the day at which any of these checks can conclude anything.
     *
     * Derived from the scheduled slot in config rather than a constant of its own: the
     * schedule in `routes/console.php` reads the same value, so moving the nightly slot
     * cannot leave this checker judging commands before they were due. Doing exactly
     * that is what emailed the superadmins a four-command miss every midnight.
     */
    protected function deviceCommandsDueAt(Carbon $day): Carbon
    {
        return $day->copy()
            ->setTimeFromTimeString(self::deviceCronAt())
            ->addMinutes(self::deviceCronStartGraceMins());
    }

    /**
     * Whether a ledger entry is still within the delay its job was dispatched with.
     *
     * The nightly commands queue every team at 00:10 but delay each job to that team's
     * shift anchor plus its module's buffer and the stagger window, so a factory whose
     * production day starts in the afternoon is legitimately unprocessed when this
     * command runs. Judging it then reported a job that had not yet had a chance to run.
     */
    protected function isNotYetDue(JobRun $run): bool
    {
        if ($run->dispatched_at === null) {
            return false;
        }

        $dueBy = $run->dispatched_at->copy()
            ->setTimeFromTimeString(Shift::anchorForTeam($run->team_id))
            ->addMinutes($this->bufferMinutesFor($run->job_name))
            ->addMinutes((int) config('app.team_dispatch_window_mins'))
            ->addMinutes(self::EXECUTION_GRACE_MINS);

        return Carbon::now()->lessThan($dueBy);
    }

    /**
     * The per-module buffer added to a team's anchor, mirroring what each dispatching
     * command passes to StaggersTeamDispatch::delayForTeam(). The sub-module interval is
     * the default because devicedata:others dispatches the three ModBales/ModBlocks/
     * ModWater jobs under it.
     */
    protected function bufferMinutesFor(string $jobName): int
    {
        $key = match (true) {
            str_contains($jobName, 'ForPith') => 'app.daily_pith_cron_interval_mins',
            str_contains($jobName, 'ForFibre') => 'app.daily_fibre_cron_interval_mins',
            str_contains($jobName, 'ForGrowBags') => 'app.growbags.daily_cron_interval_mins',
            default => 'app.daily_sub_modules_cron_interval_mins',
        };

        return (int) config($key);
    }
}
