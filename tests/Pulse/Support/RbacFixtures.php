<?php

namespace Tests\Pulse\Support;

use App\Models\Pulse\PulseTeamRole;
use App\Models\Team;
use App\Models\User;
use App\Support\Pulse\TeamRolePermissions;
use Illuminate\Support\Facades\DB;

/**
 * Shared fixtures for the three-layer Pulse RBAC Pest suites.
 */
class RbacFixtures
{
    /** @var list<string> */
    public const ROLES = ['owner', 'admin', 'supervisor', 'staff', 'reader'];

    /**
     * Full-surface Pulse team (pith + bales + blocks + growbags + husks + attendance).
     *
     * @param  array<string, mixed>  $pulseModules
     */
    public static function team(string $name = 'RBAC Factory', array $pulseModules = []): int
    {
        return TeamFactory::create($name.' '.uniqid(), true, array_replace_recursive([
            'modules' => [
                'pith' => ['is_active' => true, 'submodules' => ['bales' => true, 'blocks' => true]],
                'fibre' => ['is_active' => false, 'submodules' => ['weight' => false]],
                'growbags' => ['is_active' => true],
                'husks_stock' => ['is_active' => true],
                'attendance' => ['is_active' => true],
            ],
        ], $pulseModules));
    }

    public static function bindTeam(int $teamId): Team
    {
        $team = Team::findOrFail($teamId);
        app()->instance('current.team', $team);

        return $team;
    }

    public static function forgetTeam(): void
    {
        app()->forgetInstance('current.team');
    }

    public static function user(int $teamId, string $role, bool $superadmin = false): User
    {
        $user = User::factory()->create([
            'is_superadmin' => $superadmin ? 1 : 0,
            'is_active' => 1,
            'current_team_id' => $teamId,
        ]);

        DB::table('team_user')->insert([
            'team_id' => $teamId,
            'user_id' => $user->id,
            'role' => $role,
        ]);

        return $user;
    }

    /**
     * @return array<string, User>
     */
    public static function usersForAllRoles(int $teamId): array
    {
        $users = [];
        foreach (self::ROLES as $role) {
            $users[$role] = self::user($teamId, $role);
        }

        return $users;
    }

    /**
     * @param  list<string>  $flatCaps
     */
    public static function setPermissions(int $teamId, string $role, array $flatCaps): void
    {
        $row = PulseTeamRole::where('team_id', $teamId)->where('key', $role)->firstOrFail();
        $row->update([
            'permissions' => array_values(array_unique($flatCaps)),
        ]);
        TeamRolePermissions::forgetCache($teamId);
    }

    /**
     * @param  list<string>  $flatCaps
     */
    public static function grant(int $teamId, string $role, array $flatCaps): void
    {
        $row = PulseTeamRole::where('team_id', $teamId)->where('key', $role)->firstOrFail();
        $current = is_array($row->permissions) ? $row->permissions : [];
        $merged = array_values(array_unique(array_merge($current, $flatCaps)));
        $row->update(['permissions' => $merged]);
        TeamRolePermissions::forgetCache($teamId);
    }

    /**
     * @param  list<string>  $flatCaps
     */
    public static function revoke(int $teamId, string $role, array $flatCaps): void
    {
        $row = PulseTeamRole::where('team_id', $teamId)->where('key', $role)->firstOrFail();
        $current = is_array($row->permissions) ? $row->permissions : [];
        $filtered = array_values(array_filter(
            $current,
            fn (string $cap) => ! in_array($cap, $flatCaps, true)
        ));
        $row->update(['permissions' => $filtered]);
        TeamRolePermissions::forgetCache($teamId);
    }

    /**
     * Strip any capability whose key starts with one of the given prefixes.
     *
     * @param  list<string>  $prefixes  e.g. ['pith_inventory:', 'factory_customizations:']
     */
    public static function revokePrefixes(int $teamId, string $role, array $prefixes): void
    {
        $row = PulseTeamRole::where('team_id', $teamId)->where('key', $role)->firstOrFail();
        $current = is_array($row->permissions) ? $row->permissions : [];
        $filtered = array_values(array_filter(
            $current,
            function (string $cap) use ($prefixes) {
                foreach ($prefixes as $prefix) {
                    if (str_starts_with($cap, $prefix)) {
                        return false;
                    }
                }

                return true;
            }
        ));
        $row->update(['permissions' => $filtered]);
        TeamRolePermissions::forgetCache($teamId);
    }
}
