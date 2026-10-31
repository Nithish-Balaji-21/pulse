<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Laravel\Jetstream\Http\Controllers\CurrentTeamController;
use Laravel\Jetstream\Jetstream;

class CustomCurrentTeamController extends CurrentTeamController
{
    public function update(Request $request)
    {
        $request->validate([
            'team_id' => ['required', 'exists:teams,id'],
        ]);

        $team = Jetstream::newTeamModel()->findOrFail($request->team_id);
        $result = $request->user()->switchTeam($team); // saves to DB as default

        if (! $result) {
            return response()->json(['message' => 'User invalid for the team'], 401);
        }

        return redirect("/{$team->id}/dashboard");
    }

    public function switchTeam(Request $request, $teamId)
    {
        $user = $request->user();

        // Find the team
        $team = Jetstream::newTeamModel()->findOrFail($teamId);

        // Do NOT call $user->switchTeam($team) to avoid saving to DB.
        // Instead, just return success if the user has access.
        if ($user->is_superadmin || $user->belongsToTeam($team)) {
            return response()->json([
                'success' => true,
                'module' => $team->has_pith_module
                    ? 'pith'
                    : ($team->has_fibre_module ? 'fibre' : ($team->has_growbags_module ? 'growbags' : null)),
            ]);
        }

        return response()->json(['message' => 'User invalid for the team'], 401);
    }
}
