<?php

namespace App\Console\Commands\Pulse;

use App\Console\Traits\HasDateRangeOption;
use App\Console\Traits\RecordsJobRuns;
use App\Console\Traits\StaggersTeamDispatch;
use App\Jobs\Pulse\ProcessDeviceDataForPithJob;
use App\Models\Pulse\Shift;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ProcessDeviceDataForPith extends Command
{
    use HasDateRangeOption, RecordsJobRuns, StaggersTeamDispatch;

    // Add options for team_ids and dates in the signature
    protected $signature = 'devicedata:pith
                                {--team_ids=*}
                                {--start=}
                                {--end=}
                                {--dates=*}';

    protected $description = 'Dispatch a job per team to process device data for states and pith production';

    public function handle()
    {
        $dates = $this->getDatesToProcess();

        // getDatesToProcess() returns an empty array only when the options were rejected
        // (bad format, or end before start) and it has already printed why. Dispatching
        // with no dates hands the jobs a range they cannot process.
        if (empty($dates)) {
            return self::FAILURE;
        }

        $teams = $this->fetchPithTeamsWithStartTime($this->option('team_ids'));

        foreach ($teams as $position => $team) {

            // Today + buffer time after the 1st shift start_time, staggered per team
            $delayUntil = $this->delayForTeam(
                $team->start_time,
                (int) config('app.daily_pith_cron_interval_mins'),
                $position
            );

            // Dispatch one job per team
            ProcessDeviceDataForPithJob::dispatch($team->team_id, $team->device_id, $dates)
                ->forScheduledCompilation($this->isScheduledRun())->delay($delayUntil);

            $this->recordDispatch(ProcessDeviceDataForPithJob::class, $team->team_id, $dates);

            $this->info("Queued team {$team->team_id} at {$delayUntil->toDateTimeString()}");
        }

        DB::table('cron_runs')->insert([
            'job_name' => 'devicedata:pith',
            'run_date' => now()->toDateString(),
            'ran_at' => now(),
        ]);
    }

    protected function fetchPithTeamsWithStartTime($team_ids = [])
    {
        $query = DB::table('teams')
            ->join('team_pulse', 'teams.id', '=', 'team_pulse.team_id')
            ->join('pulse_shifts', 'teams.id', '=', 'pulse_shifts.team_id')
            ->join('pulse_devices', 'teams.id', '=', 'pulse_devices.team_id')
            ->leftJoin('pulse_settings', function ($join) {
                $join->on('teams.id', '=', 'pulse_settings.team_id')
                    ->where('pulse_settings.key', Shift::ANCHOR_SETTING_KEY);
            })
            ->select(
                'teams.id as team_id',
                'pulse_devices.device_id as device_id',
                DB::raw("COALESCE(MIN(pulse_settings.value), '".Shift::DEFAULT_ANCHOR."') as start_time")
            )
            ->where('team_pulse.modules->pulse->modules->pith->is_active', true)
            ->groupBy('team_id', 'device_id');

        if (! empty($team_ids)) {
            $query->whereIn('teams.id', $team_ids);
        }

        return $query->get();
    }
}
