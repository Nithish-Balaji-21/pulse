<?php

namespace App\Actions\Jetstream;

use App\Models\Team;
use App\Models\User;
use App\Rules\CoordinatePair;
use App\Support\Pulse\GeofencingModule;
use App\Support\Pulse\ProductionEntryPolicyKeys;
use App\Support\Pulse\TeamRolePermissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Laravel\Jetstream\Contracts\UpdatesTeamNames;

class UpdateTeamName implements UpdatesTeamNames
{
    /**
     * Validate and update the given team's name.
     *
     * @param  array<string, string>  $input
     */
    public function update(User $user, Team $team, array $input): void
    {
        Gate::forUser($user)->authorize('update', $team);

        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'pincode' => ['nullable', 'string', 'max:10'],
            'coordinates' => ['nullable', 'string', 'max:60', new CoordinatePair],
            'license_valid_till' => ['nullable', 'date'],
            'is_active' => ['nullable', 'boolean'],
            'has_pith_module' => ['nullable', 'boolean'],
            'has_pith_module_blocks' => ['nullable', 'boolean'],
            'has_pith_module_bales' => ['nullable', 'boolean'],
            'has_fibre_module' => ['nullable', 'boolean'],
            'has_fibre_module_weight' => ['nullable', 'boolean'],
            'has_growbags_module' => ['nullable', 'boolean'],
            'has_husks_stock_module' => ['nullable', 'boolean'],
            'has_water_meter_module' => ['nullable', 'boolean'],
            'has_attendance_module' => ['nullable', 'boolean'],
            'has_geofencing_module' => ['nullable', 'boolean'],
            'geofencing_max_boundaries' => ['nullable', 'integer', 'min:1', 'max:'.GeofencingModule::absoluteMaxBoundaries()],
        ])->validateWithBag('updateTeamName');

        $team->forceFill([
            'name' => $input['name'],
        ])->save();

        if (isset($input['address_line_1']) || isset($input['city']) || isset($input['pincode'])) {
            $team->address()->updateOrCreate(
                ['type' => 'factory'],
                [
                    'address_line_1' => $input['address_line_1'] ?? null,
                    'address_line_2' => $input['address_line_2'] ?? null,
                    'city' => $input['city'] ?? null,
                    'state' => $input['state'] ?? null,
                    'pincode' => $input['pincode'] ?? null,
                ]
            );
        }

        $pulse = $team->pulse;
        $modules = $pulse?->modules ?? [
            'pulse' => [
                'is_active' => true,
                'modules' => [],
            ],
        ];

        $pithActive = (bool) ($input['has_pith_module'] ?? false);
        $fibreActive = (bool) ($input['has_fibre_module'] ?? false);

        $modules['pulse']['modules']['pith'] = [
            'is_active' => $pithActive,
            'submodules' => [
                'blocks' => $pithActive && (bool) ($input['has_pith_module_blocks'] ?? false),
                'bales' => $pithActive && (bool) ($input['has_pith_module_bales'] ?? false),
            ],
        ];
        $modules['pulse']['modules']['fibre'] = [
            'is_active' => $fibreActive,
            'submodules' => [
                'weight' => $fibreActive && (bool) ($input['has_fibre_module_weight'] ?? false),
            ],
        ];
        $modules['pulse']['modules']['growbags'] = [
            'is_active' => (bool) ($input['has_growbags_module'] ?? false),
        ];
        $modules['pulse']['modules']['husks_stock'] = [
            'is_active' => (bool) ($input['has_husks_stock_module'] ?? false),
        ];
        $modules['pulse']['modules']['water_meter'] = [
            'is_active' => (bool) ($input['has_water_meter_module'] ?? false),
        ];
        $modules['pulse']['modules']['attendance'] = [
            'is_active' => (bool) ($input['has_attendance_module'] ?? false),
        ];
        $modules['pulse']['modules']['geofencing'] = GeofencingModule::payload(
            (bool) ($input['has_geofencing_module'] ?? false),
            isset($input['geofencing_max_boundaries'])
                ? (int) $input['geofencing_max_boundaries']
                : GeofencingModule::maxBoundaries($team)
        );

        $team->pulse()->updateOrCreate(
            [],
            [
                'coordinates' => $input['coordinates'] ?? ($team->pulse->coordinates ?? null),
                'license_valid_till' => $input['license_valid_till'] ?? ($team->pulse->license_valid_till ?? null),
                'is_active' => $input['is_active'] ?? ($team->pulse->is_active ?? 1),
                'modules' => $modules,
            ]
        );

        TeamRolePermissions::syncOnModuleChange($team->id);

        $this->seedPolicySettingsIfNeeded($team->id, $pithActive, $pithActive && (bool) ($input['has_pith_module_bales'] ?? false));
    }

    private function seedPolicySettingsIfNeeded(int $teamId, bool $hasPith, bool $hasBales): void
    {
        if (! $hasPith) {
            return;
        }

        $now = now();
        $keysToSeed = [ProductionEntryPolicyKeys::BLOCKS, ProductionEntryPolicyKeys::LOADS];

        if ($hasBales) {
            $keysToSeed[] = ProductionEntryPolicyKeys::BALES;
        }

        foreach ($keysToSeed as $key) {
            $row = ProductionEntryPolicyKeys::row($teamId, $key, $now);

            DB::table('pulse_settings')->updateOrInsert(
                ['team_id' => $row['team_id'], 'module' => $row['module'], 'key' => $row['key']],
                $row
            );
        }
    }
}
