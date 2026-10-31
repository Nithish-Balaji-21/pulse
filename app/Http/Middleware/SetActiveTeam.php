<?php

namespace App\Http\Middleware;

use App\Models\Team;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class SetActiveTeam
{
    public function handle(Request $request, Closure $next): Response
    {
        // Resolve team manually — don't rely on route model binding
        $routeTeam = $request->route('team');
        $team = $routeTeam instanceof Team ? $routeTeam : Team::find($routeTeam);

        $user = $request->user();

        // An id naming no team is treated like one the user can't open: both
        // arrive here off the same stale cookie, and both need a way out.
        if (! $team || ! $user->canAccessTeam($team)) {
            return $this->denyTeam($request, $user, $team?->id);
        }

        // Set team for this request only — NO database write
        $user->forceFill(['current_team_id' => $team->id]);
        app()->instance('current.team', $team);

        $response = $next($request);

        // BinaryFileResponse (file downloads) doesn't support withCookie()
        if ($response instanceof BinaryFileResponse) {
            return $response;
        }

        $response->withCookie(cookie()->forever('last_team_id', $team->id));

        return $response;
    }

    /**
     * Send the user to a factory they can actually open instead of dead-ending.
     *
     * `last_team_id` is a forever cookie, so it outlives the session that set it
     * in two ways. On a shared browser it hands the next user the previous
     * user's factory. And it survives that factory going away — deleted, or the
     * app repointed at another database — leaving an id that resolves to no team
     * at all. Either way /dashboard redirects onto the cookie right after login,
     * before anything validates it, so aborting here would strand the user for
     * good: nothing else ever clears the value. The redirect below re-enters
     * this middleware for a team they do belong to, which re-stamps the cookie.
     *
     * @param  int|null  $deniedId  The refused team, or null when the id named no team.
     */
    private function denyTeam(Request $request, User $user, ?int $deniedId): Response
    {
        $message = $deniedId
            ? 'You are not authorized to view this factory'
            : 'That factory no longer exists';

        if ($request->wantsJson()) {
            return response()->json(['message' => $message], $deniedId ? 403 : 404);
        }

        $fallback = $this->fallbackTeam($user, $deniedId);

        if (! $fallback) {
            return response()->view('errors.no-factory', [], $deniedId ? 403 : 404);
        }

        return redirect("/{$fallback->id}/dashboard")
            ->with('warning', "{$message}, so {$fallback->name} was opened instead.");
    }

    /**
     * The user's own current team when they still belong to it, otherwise any
     * team they belong to. Never the denied team, so the redirect cannot loop.
     */
    private function fallbackTeam(User $user, ?int $deniedId): ?Team
    {
        $currentTeam = $user->currentTeam;

        if ($currentTeam && $currentTeam->id !== $deniedId && $user->canAccessTeam($currentTeam)) {
            return $currentTeam;
        }

        return $user->allTeams()->first(fn (Team $team) => $team->id !== $deniedId);
    }
}
