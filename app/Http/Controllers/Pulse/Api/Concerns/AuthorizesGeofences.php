<?php

namespace App\Http\Controllers\Pulse\Api\Concerns;

use App\Models\Pulse\GeofenceLocation;
use App\Models\Team;
use App\Models\User;
use App\Support\Pulse\GeofencingModule;
use Illuminate\Support\Facades\DB;

trait AuthorizesGeofences
{
    private function assertTeam($team): int
    {
        $teamId = (int) $team;
        $currentTeamId = (int) app('current.team')->id;
        abort_unless($teamId === $currentTeamId, 403, 'Factory mismatch.');

        return $teamId;
    }

    private function currentTeam(): Team
    {
        return app('current.team');
    }

    private function assertGeofencingEnabled(): void
    {
        abort_unless(
            GeofencingModule::isActive($this->currentTeam()),
            403,
            'Geofencing is not enabled for this factory.'
        );
    }

    private function assertCanViewGeofences(User $user, string $message = 'You do not have permission to view geofence locations.'): void
    {
        if ($user->isSuperadmin()) {
            return;
        }

        abort_unless($user->allows('geofence_locations', 'view'), 403, $message);
    }

    private function assertCanCreateGeofences(User $user, string $message = 'You do not have permission to create geofence locations.'): void
    {
        if ($user->isSuperadmin()) {
            return;
        }

        abort_unless($user->allows('geofence_locations', 'create'), 403, $message);
    }

    private function assertCanEditGeofences(User $user, string $message = 'You do not have permission to edit geofence locations.'): void
    {
        if ($user->isSuperadmin()) {
            return;
        }

        abort_unless($user->allows('geofence_locations', 'edit'), 403, $message);
    }

    private function assertCanDeleteGeofences(User $user, string $message = 'You do not have permission to delete geofence locations.'): void
    {
        if ($user->isSuperadmin()) {
            return;
        }

        abort_unless($user->allows('geofence_locations', 'delete'), 403, $message);
    }

    /** @deprecated Use assertCanViewGeofences — kept for call-site clarity in event controllers. */
    private function assertCanViewGeofenceEvents(User $user): void
    {
        $this->assertCanViewGeofences($user, 'You do not have permission to view staff geofence events.');
    }

    private function usedBoundaryCount(int $teamId): int
    {
        return (int) GeofenceLocation::query()
            ->where('team_id', $teamId)
            ->count();
    }

    private function maxBoundaries(): int
    {
        return GeofencingModule::maxBoundaries($this->currentTeam());
    }

    private function teamMember(int $teamId, int $userId): ?User
    {
        $belongs = DB::table('team_user')
            ->where('team_id', $teamId)
            ->where('user_id', $userId)
            ->exists();

        if (! $belongs) {
            return null;
        }

        return User::find($userId);
    }
}
