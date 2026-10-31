<?php

use App\Support\Pulse\TeamRolePermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Moves the mobile app's daily husk entry popup from the supervisor role to staff.
 *
 * The prompt asks whoever weighs the incoming husks to record yesterday's intake, and on the yard
 * that is the staff on the floor rather than the supervisor over them. `husk_inventory:update`
 * travels with it: a reminder to file an entry the role cannot post is nothing but noise, and the
 * endpoint behind the popup requires the write before it will prompt anyone.
 *
 * Stored role permissions are authoritative once a team has been seeded — sanitizePermissions
 * expands the stored flat list into a matrix, so an action absent from that list reads as false
 * rather than falling back to the config default. Changing the defaults alone would therefore
 * leave every existing team prompting its supervisors.
 *
 * Only teams still in the default shape are moved, keyed on the supervisor holding the reminder.
 * A team whose owner has already placed the prompt elsewhere — or revoked it outright — has made
 * a deliberate choice from the Roles page, and this migration leaves it as it stands.
 */
return new class extends Migration
{
    private const CAPABILITY = 'husk_inventory:daily_reminder';

    private const WRITE = 'husk_inventory:update';

    private const VIEW = 'husk_inventory:view';

    public function up(): void
    {
        $this->eachDefaultShapedTeam(function (object $supervisor, ?object $staff) {
            $this->store($supervisor, $this->without($supervisor->capabilities, self::CAPABILITY));

            if ($staff === null) {
                return;
            }

            $this->store($staff, $this->with($staff->capabilities, [self::VIEW, self::WRITE, self::CAPABILITY]));
        }, heldBy: 'supervisor', movedTo: 'staff');
    }

    public function down(): void
    {
        $this->eachDefaultShapedTeam(function (object $staff, ?object $supervisor) {
            $this->store($staff, $this->without($staff->capabilities, self::CAPABILITY, self::WRITE));

            if ($supervisor === null) {
                return;
            }

            $this->store($supervisor, $this->with($supervisor->capabilities, [self::VIEW, self::WRITE, self::CAPABILITY]));
        }, heldBy: 'staff', movedTo: 'supervisor');
    }

    /**
     * Walk the teams where `$heldBy` currently holds the reminder, handing the callback that role
     * row and its `$movedTo` counterpart (null when the team has no such row).
     *
     * @param  callable(object, object|null): void  $callback
     */
    private function eachDefaultShapedTeam(callable $callback, string $heldBy, string $movedTo): void
    {
        $rolesByTeam = DB::table('pulse_team_roles')
            ->whereIn('key', [$heldBy, $movedTo])
            ->orderBy('id')
            ->get()
            ->map(function (object $role) {
                $capabilities = json_decode($role->permissions ?? '', true);

                // Only the flat capability-list form is rewritten; a matrix-shaped payload is
                // re-derived from the config defaults on its next sanitize pass anyway.
                $role->capabilities = is_array($capabilities) && $capabilities !== [] && ! is_array(reset($capabilities))
                    ? array_values($capabilities)
                    : null;

                return $role;
            })
            ->filter(fn (object $role) => $role->capabilities !== null)
            ->groupBy('team_id');

        foreach ($rolesByTeam as $roles) {
            $source = $roles->firstWhere('key', $heldBy);

            if (! $source || ! in_array(self::CAPABILITY, $source->capabilities, true)) {
                continue;
            }

            $callback($source, $roles->firstWhere('key', $movedTo));
        }
    }

    /**
     * @param  list<string>  $capabilities
     * @param  list<string>  $granted
     * @return list<string>
     */
    private function with(array $capabilities, array $granted): array
    {
        $capabilities = array_values(array_unique([...$capabilities, ...$granted]));
        sort($capabilities);

        return $capabilities;
    }

    /**
     * @param  list<string>  $capabilities
     * @return list<string>
     */
    private function without(array $capabilities, string ...$revoked): array
    {
        return array_values(array_filter(
            $capabilities,
            fn (string $capability) => ! in_array($capability, $revoked, true)
        ));
    }

    /**
     * @param  list<string>  $capabilities
     */
    private function store(object $role, array $capabilities): void
    {
        DB::table('pulse_team_roles')->where('id', $role->id)->update([
            'permissions' => json_encode($capabilities),
            'updated_at' => now(),
        ]);

        TeamRolePermissions::forgetCache((int) $role->team_id);
    }
};
