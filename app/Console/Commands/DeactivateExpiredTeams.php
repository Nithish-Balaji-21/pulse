<?php

namespace App\Console\Commands;

use App\Models\Team;
use Carbon\Carbon;
use Illuminate\Console\Command;

class DeactivateExpiredTeams extends Command
{
    /**
     * The name and signature of the console command.
     *
     * You can run it manually: php artisan teams:deactivate-expired
     */
    protected $signature = 'teams:deactivate-expired';

    /**
     * The console command description.
     */
    protected $description = 'Deactivate active teams whose license has expired';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $today = Carbon::today()->toDateString();
        $expiredTeams = Team::whereHas('pulse', function ($query) use ($today) {
            $query->where('is_active', true)
                ->where('license_valid_till', '<', $today);
        })->get();

        foreach ($expiredTeams as $team) {
            if ($team->pulse) {
                $team->pulse->is_active = false;
                $team->pulse->save();
            }
            $team->save();
        }
    }
}
