<?php

namespace App\Http\Middleware;

use App\Models\Team;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ResolveActiveTeam
{
    /**
     * Bind the team this request runs in once per request.
     *
     * Appended to the whole web group, so it also covers routes with no `{team}`
     * segment (teams.create, livewire/update) where there is nothing in the URL
     * to resolve from and `User::activeTeam()` has to fall back to the
     * `last_team_id` cookie. Runs before the `active.team` route middleware,
     * which re-binds the same team after checking membership.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $team = $this->resolveTeam($request);

        if ($team) {
            app()->instance('current.team', $team);
        }

        return $next($request);
    }

    /**
     * Resolve from the route when the URL carries a team, otherwise from the user.
     *
     * Membership for a route-supplied team is enforced by SetActiveTeam, which
     * runs next; an unknown id is left unbound for it to redirect away from.
     */
    private function resolveTeam(Request $request): ?Team
    {
        $routeTeam = $request->route()?->parameter('team');

        if ($routeTeam) {
            return $routeTeam instanceof Team ? $routeTeam : Team::find($routeTeam);
        }

        try {
            return $request->user()?->activeTeam();
        } catch (\Throwable $e) {
            // Stale or corrupt "remember me" cookie — treat as guest.
            Log::warning('Could not resolve the active team (bad cookie?): '.$e->getMessage());

            return null;
        }
    }
}
