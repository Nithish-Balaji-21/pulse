<?php

namespace App\Http\Middleware;

use App\Models\Team;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Jetstream\Jetstream;
use Symfony\Component\HttpFoundation\Response;

class VerifyCurrentTeam
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();
        $currentTeam = $user?->currentTeam;

        if (! $user || ! $currentTeam) {
            return response()->json(['error' => 'User or factory not found'], 400);
        }

        // ✅ Set current.team in app container — same as SetActiveTeam does for web
        app()->instance('current.team', $currentTeam);

        $currentTeamId = $currentTeam->id;

        $teamId = $request->route('factory_id') ?? $request->input('factory_id');
        if ($teamId && $currentTeamId != $teamId) {
            // Check if user belongs to this team
            $team = Jetstream::newTeamModel()->find($teamId);
            if ($team && ($user->is_superadmin || $user->belongsToTeam($team))) {
                // Dynamically switch the team in-memory for THIS request only
                $user->forceFill(['current_team_id' => $team->id]);
                $user->setRelation('currentTeam', $team);
                app()->instance('current.team', $team);
            } else {
                return response()->json([
                    'message' => 'User does not belong to this factory or factory does not exist',
                ], 403);
            }
        }

        // Mirrors EnsureTeamIsActive on the web side — checked after the
        // factory_id switch above so it applies to the effective team.
        if (! app('current.team')->hasPulseAccess()) {
            return response()->json([
                'message' => 'This factory does not have an active Pulse subscription',
            ], 403);
        }

        return $next($request);
    }
}
