<?php

namespace App\Console\Commands\Pulse;

use App\Jobs\Pulse\ProcessHusksDailyStockJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ProcessHusksDailyStock extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'husks:daily-stock {--team_ids=*}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispatch jobs to create daily husk stock entries for teams with the module enabled.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Fetch teams with has_husks_stock_module enabled and their Shift 1 start time
        $teams = $this->fetchHuskTeams($this->option('team_ids'));

        if ($teams->isEmpty()) {
            $this->info('No teams found with has_husks_stock_module enabled.');

            return;
        }

        foreach ($teams as $team) {
            // Dispatch the job immediately without delay
            ProcessHusksDailyStockJob::dispatch($team->team_id);

            $this->info("Job dispatched for Team ID: {$team->team_id}");
        }
    }

    /**
     * Fetch teams that have the husk module.
     */
    protected function fetchHuskTeams($teamIds = [])
    {
        $query = DB::table('teams')
            ->join('team_pulse', 'teams.id', '=', 'team_pulse.team_id')
            ->select('teams.id as team_id')
            ->where('team_pulse.modules->pulse->modules->husks_stock->is_active', true);

        if (! empty($teamIds)) {
            $query->whereIn('teams.id', $teamIds);
        }

        return $query->get();
    }
}
