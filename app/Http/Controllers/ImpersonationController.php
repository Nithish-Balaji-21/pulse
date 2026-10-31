<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Impersonation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ImpersonationController extends Controller
{
    /**
     * Begin impersonating $user, read-only.
     *
     * Gated by `impersonate-users` on the route; the remaining checks are about
     * the target rather than the actor, so they answer with a message.
     */
    public function start(Request $request, User $user): RedirectResponse
    {
        $impersonator = $request->user();

        if ($reason = Impersonation::reasonCannotImpersonate($impersonator, $user)) {
            return back()->with('error', $reason);
        }

        Impersonation::start($impersonator, $user);

        // Deliberately not activeTeam(): this route carries no {team} segment, so
        // ResolveActiveTeam bound the *impersonator's* factory and activeTeam()
        // prefers that binding — sending them into a factory the target may not
        // belong to, which SetActiveTeam would then bounce or 403.
        $team = $user->currentTeam && $user->canAccessTeam($user->currentTeam)
            ? $user->currentTeam
            : $user->allTeams()->first();

        return redirect($team ? "/{$team->id}/dashboard" : '/dashboard')
            ->with('info', "You are now viewing CoiioT as {$user->name} (read-only).");
    }

    /**
     * Hand the session back to the impersonator.
     *
     * Deliberately outside the `impersonate-users` gate: the authenticated user
     * during a session is the target, who is not a superadmin and would fail it.
     */
    public function stop(Request $request): RedirectResponse
    {
        $returnTeamId = Impersonation::returnTeamId();
        $impersonator = Impersonation::stop();

        if (! $impersonator) {
            return redirect('/dashboard');
        }

        $teamId = $returnTeamId ?? $impersonator->current_team_id;

        $response = redirect($teamId ? "/{$teamId}/dashboard" : '/dashboard')
            ->with('success', 'Impersonation ended — you are back as yourself.');

        // SetActiveTeam stamped the target's factory over the impersonator's
        // forever-cookie; put their own back so they land where they left off.
        if ($returnTeamId) {
            $response->withCookie(cookie()->forever('last_team_id', $returnTeamId));
        }

        return $response;
    }

    /**
     * Toggle write mode for the running session.
     */
    public function writeMode(Request $request): RedirectResponse
    {
        if (! Impersonation::isActive()) {
            return back();
        }

        $enable = $request->boolean('enable');

        Impersonation::setWriteMode($enable);

        return back()->with(
            $enable ? 'warning' : 'success',
            $enable
                ? 'Write mode ON — changes you make are saved as this user and recorded in the audit log.'
                : 'Write mode off — this session is read-only again.'
        );
    }
}
