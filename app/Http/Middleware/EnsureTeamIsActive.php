<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureTeamIsActive
{
    public function handle(Request $request, Closure $next)
    {
        $team = $request->user()?->currentTeam;

        // Denies teams without Pulse, deactivated teams, and teams whose
        // licence has lapsed. See Team::hasPulseAccess().
        if (! $team?->hasPulseAccess()) {
            return response()->view('errors.401', [], 401);
        }

        return $next($request);
    }
}
