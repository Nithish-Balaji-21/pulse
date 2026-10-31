<?php

namespace Tests\Pulse\Support;

use App\Support\Pulse\GeofencingModule;
use App\Support\Pulse\TeamRolePermissions;
use Illuminate\Support\Facades\DB;

/**
 * Builds a team the way the schema now expects: the teams row carries little more
 * than a name, while activation and the enabled modules live on team_pulse.
 *
 * Kept in one place so a further move of these columns is a single edit rather
 * than a sweep through every Pulse test.
 */
class TeamFactory
{
    /**
     * @param  array<string, mixed>  $pulseModules  Overrides for the pulse module map.
     */
    public static function create(string $name, bool $withPithModule = true, array $pulseModules = []): int
    {
        $teamId = (int) DB::table('teams')->insertGetId([
            'name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $modules = [
            'pulse' => array_replace_recursive([
                'is_active' => true,
                'modules' => [
                    'pith' => [
                        'is_active' => $withPithModule,
                        'submodules' => ['bales' => true, 'blocks' => true],
                    ],
                    'fibre' => [
                        'is_active' => false,
                        'submodules' => ['weight' => false],
                    ],
                    'growbags' => [
                        'is_active' => false,
                    ],
                    'husks_stock' => [
                        'is_active' => $withPithModule,
                    ],
                    'attendance' => [
                        'is_active' => true,
                    ],
                    'geofencing' => [
                        'is_active' => false,
                        'max_boundaries' => GeofencingModule::defaultMaxBoundaries(),
                    ],
                ],
            ], $pulseModules),
        ];

        DB::table('team_pulse')->insert([
            'team_id' => $teamId,
            'modules' => json_encode($modules),
            'is_active' => 1,
            'license_valid_till' => now()->addYear()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        TeamRolePermissions::seedDefaultsForTeam($teamId);

        return $teamId;
    }
}
