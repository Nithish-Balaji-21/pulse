<?php

namespace App\Console\Commands\Pulse;

use App\Console\Traits\HasDateRangeOption;
use App\Console\Traits\RecordsJobRuns;
use App\Console\Traits\StaggersTeamDispatch;
use App\Jobs\Pulse\ProcessDeviceDataForModBalesJob;
use App\Jobs\Pulse\ProcessDeviceDataForModBlocksJob;
use App\Jobs\Pulse\ProcessDeviceDataForModWaterJob;
use App\Models\Pulse\Shift;
use App\Traits\CastsJsonBoolean;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class ProcessDeviceDataForSubModules extends Command
{
    use CastsJsonBoolean;
    use HasDateRangeOption;
    use RecordsJobRuns;
    use StaggersTeamDispatch;

    // Add options for team_ids and dates in the signature
    protected $signature = 'devicedata:others
                                {--team_ids=*}
                                {--modules=*}
                                {--start=}
                                {--end=}
                                {--dates=*}';

    protected $description = 'Dispatch a job per team to process other modules (small bales, pith blocks, water meter)';

    public function handle()
    {
        $dates = $this->getDatesToProcess();

        // getDatesToProcess() returns an empty array only when the options were rejected
        // (bad format, or end before start) and it has already printed why. Dispatching
        // with no dates hands the jobs a range they cannot process.
        if (empty($dates)) {
            return self::FAILURE;
        }

        $teams = $this->fetchTeamsOfOtherModules($this->option('team_ids'));
        $selectedModules = $this->option('modules');

        foreach ($teams as $position => $team) {
            // Delay from the team's start time, staggered per team
            $delayUntil = $this->delayForTeam(
                $team->start_time,
                (int) config('app.daily_sub_modules_cron_interval_mins'),
                $position
            );

            if ($this->castJsonBool($team->has_pith_module_bales) && $this->shouldProcessModule('bales', $selectedModules)) {
                ProcessDeviceDataForModBalesJob::dispatch($team->team_id, $team->device_id, $dates)
                    ->forScheduledCompilation($this->isScheduledRun())->delay($delayUntil);
                $this->recordDispatch(ProcessDeviceDataForModBalesJob::class, $team->team_id, $dates);
                $this->info("Processed team {$team->team_id} for other modules: pith module - fibre bales");
            }

            if ($this->castJsonBool($team->has_pith_module_blocks) && $this->shouldProcessModule('blocks', $selectedModules)) {
                ProcessDeviceDataForModBlocksJob::dispatch($team->team_id, $team->device_id, $dates)
                    ->forScheduledCompilation($this->isScheduledRun())->delay($delayUntil);
                $this->recordDispatch(ProcessDeviceDataForModBlocksJob::class, $team->team_id, $dates);
                $this->info("Processed team {$team->team_id} for other modules: pith module - pith blocks");
            }

            if ($this->castJsonBool($team->has_water_meter_module) && $this->shouldProcessModule('water', $selectedModules)) {
                ProcessDeviceDataForModWaterJob::dispatch($team->team_id, $team->device_id, $dates)
                    ->forScheduledCompilation($this->isScheduledRun())->delay($delayUntil);
                $this->recordDispatch(ProcessDeviceDataForModWaterJob::class, $team->team_id, $dates);
                $this->info("Processed team {$team->team_id}, device {$team->device_id}, for water meter");
            }

        }

        DB::table('cron_runs')->insert([
            'job_name' => 'devicedata:others',
            'run_date' => now()->toDateString(),
            'ran_at' => now(),
        ]);

        // Trigger the ProductionAllSummary command after this cron finishes
        // Artisan::call('summary:productions');
    }

    protected function fetchTeamsOfOtherModules($team_ids = [])
    {
        $query = DB::table('teams')
            ->join('team_pulse', 'teams.id', '=', 'team_pulse.team_id')
            ->join('pulse_devices', 'teams.id', '=', 'pulse_devices.team_id')
            ->join('pulse_shifts', 'teams.id', '=', 'pulse_shifts.team_id')
            ->leftJoin('pulse_settings', function ($join) {
                $join->on('teams.id', '=', 'pulse_settings.team_id')
                    ->where('pulse_settings.key', Shift::ANCHOR_SETTING_KEY);
            })
            ->select(
                'teams.id as team_id',
                'pulse_devices.device_id as device_id',
                DB::raw("COALESCE(MIN(pulse_settings.value), '".Shift::DEFAULT_ANCHOR."') as start_time"),
                'team_pulse.modules->pulse->modules->pith->submodules->bales as has_pith_module_bales',
                'team_pulse.modules->pulse->modules->pith->submodules->blocks as has_pith_module_blocks',
                'team_pulse.modules->pulse->modules->water_meter->is_active as has_water_meter_module'
            )
            ->where(function ($q) {
                $q->where('team_pulse.modules->pulse->modules->pith->submodules->bales', true)
                    ->orWhere('team_pulse.modules->pulse->modules->pith->submodules->blocks', true)
                    ->orWhere('team_pulse.modules->pulse->modules->water_meter->is_active', true);
            })
            ->groupBy('teams.id', 'pulse_devices.device_id');

        if (! empty($team_ids)) {
            $query->whereIn('teams.id', $team_ids);
        }

        return $query->get();
    }

    private function shouldProcessModule(string $module, array $selectedModules): bool
    {
        return empty($selectedModules) || in_array($module, $selectedModules);
    }
}
