<?php

namespace App\Http\Controllers;

use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class ManualCronController extends Controller
{
    /**
     * Manually run yesterday's cron commands for a specific team by ID.
     *
     * @param  int  $teamId
     * @return JsonResponse
     */
    public function runYesterdayCronsForTeamById($teamId)
    {
        if (! Gate::allows('view-superadmin-settings')) {
            return response()->json([
                'success' => false,
                'error' => 'You are not authorized to run manual cron jobs.',
            ], 403);
        }

        // Fetch the team by ID
        $team = Team::find($teamId);

        if (! $team) {
            return response()->json([
                'success' => false,
                'error' => "Team with ID {$teamId} not found.",
            ], 404);
        }

        // Run every active module's device-data command for this factory
        // (not else-if — a factory can have both pith and fibre).
        $commandsToRun = [];

        if ($team->has_pith_module) {
            $commandsToRun[] = 'devicedata:pith';
        }

        if ($team->has_fibre_module) {
            $commandsToRun[] = 'devicedata:fibre';
        }

        $commandsToRun[] = 'devicedata:others';

        try {
            foreach ($commandsToRun as $command) {
                Artisan::call($command, [
                    '--team_ids' => [$team->id],
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => "Yesterday's cron manually triggered for {$team->name}. Please check email and push notifications.",
            ]);
        } catch (\Exception $e) {
            // Log the error for debugging purposes
            Log::error("Manual cron trigger failed for team {$teamId}: ".$e->getMessage());

            return response()->json([
                'success' => false,
                'error' => 'An error occurred while running the cron jobs.',
            ], 500);
        }
    }
}
