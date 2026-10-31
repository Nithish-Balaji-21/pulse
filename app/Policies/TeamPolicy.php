<?php

namespace App\Policies;

use App\Models\Team;
use App\Models\User;
use App\Support\Pulse\TeamRolePermissions;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Support\Facades\DB;

class TeamPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Team $team): bool
    {
        return $user->is_superadmin == 1 || $user->ownsTeam($team) || $user->belongsToTeam($team);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->is_superadmin == 1;
    }

    /**
     * Determine whether the user can update the model (team name / modules).
     *
     * Superadmin, team owner, or a member with factory_configuration:edit
     * on this team (matrix "Edit Team Name").
     */
    public function update(User $user, Team $team): bool
    {
        if ($user->is_superadmin == 1 || $user->ownsTeam($team)) {
            return true;
        }

        if (! $user->belongsToTeam($team)) {
            return false;
        }

        $role = DB::table('team_user')
            ->where('team_id', $team->id)
            ->where('user_id', $user->id)
            ->value('role');

        if (! $role) {
            return false;
        }

        return TeamRolePermissions::allows((int) $team->id, (string) $role, 'factory_configuration', 'edit');
    }

    /**
     * Determine whether the user can add team members.
     */
    public function addTeamMember(User $user, Team $team): bool
    {
        if ($user->is_superadmin == 1) {
            return true;
        }

        if (! $user->belongsToTeam($team)) {
            return false;
        }

        $role = DB::table('team_user')
            ->where('team_id', $team->id)
            ->where('user_id', $user->id)
            ->value('role');

        if (! $role) {
            return false;
        }

        return TeamRolePermissions::allows((int) $team->id, (string) $role, 'manage_users', 'create');
    }

    /**
     * Determine whether the user can update team member permissions.
     */
    public function updateTeamMember(User $user, Team $team): bool
    {
        if ($user->is_superadmin == 1) {
            return true;
        }

        if (! $user->belongsToTeam($team)) {
            return false;
        }

        $role = DB::table('team_user')
            ->where('team_id', $team->id)
            ->where('user_id', $user->id)
            ->value('role');

        if (! $role) {
            return false;
        }

        return TeamRolePermissions::allows((int) $team->id, (string) $role, 'manage_users', 'edit');
    }

    /**
     * Determine whether the user can remove team members.
     */
    public function removeTeamMember(User $user, Team $team): bool
    {
        if ($user->is_superadmin == 1) {
            return true;
        }

        if (! $user->belongsToTeam($team)) {
            return false;
        }

        $role = DB::table('team_user')
            ->where('team_id', $team->id)
            ->where('user_id', $user->id)
            ->value('role');

        if (! $role) {
            return false;
        }

        return TeamRolePermissions::allows((int) $team->id, (string) $role, 'manage_users', 'delete');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user): bool
    {
        return $user->is_superadmin == 1;
    }
}
