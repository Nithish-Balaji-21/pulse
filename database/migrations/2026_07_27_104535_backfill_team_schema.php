<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('teams') || ! Schema::hasTable('team_address')) {
            return;
        }

        $teams = DB::table('teams')->get();
        $productsColumn = Schema::hasColumn('teams', 'products') ? 'products' : null;
        $featuresColumn = Schema::hasColumn('teams', 'features') ? 'features' : null;

        foreach ($teams as $team) {
            $legacyProducts = $productsColumn
                ? (json_decode($team->{$productsColumn} ?? '{}', true) ?? [])
                : [];

            // teams.features holds simple product flags; derive from legacy products JSON if empty.
            if ($featuresColumn && empty($team->features)) {
                $flags = [];
                if ($legacyProducts['pulse']['is_active'] ?? true) {
                    $flags['pulse'] = true;
                }

                DB::table('teams')->where('id', $team->id)->update([
                    'features' => json_encode($flags ?: ['pulse' => true]),
                ]);
            }

            $existingAddress = DB::table('team_address')->where('team_id', $team->id)->where('type', 'factory')->first();

            if ($existingAddress) {
                $addressId = $existingAddress->id;
            } else {
                $addressId = DB::table('team_address')->insertGetId([
                    'team_id' => $team->id,
                    'type' => 'factory',
                    'address_line_1' => $team->address ?? null,
                    'pincode' => $team->pincode ?? null,
                    'created_at' => $team->created_at ?? now(),
                    'updated_at' => $team->updated_at ?? now(),
                ]);
            }

            if (! DB::table('team_pulse')->where('team_id', $team->id)->exists()) {
                DB::table('team_pulse')->insert([
                    'team_id' => $team->id,
                    'factory_address_id' => $addressId,
                    'modules' => $productsColumn ? ($team->{$productsColumn} ?? null) : null,
                    'coordinates' => $team->coordinates ?? null,
                    'license_valid' => $team->license_valid ?? null,
                    'installed_date' => $team->installed_date ?? null,
                    'is_active' => $team->is_active ?? 1,
                    'created_at' => $team->created_at ?? now(),
                    'updated_at' => $team->updated_at ?? now(),
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Only remove rows that this migration would have created (not pre-seeded static addresses).
        $teamIds = DB::table('teams')->pluck('id');

        DB::table('team_pulse')->whereIn('team_id', $teamIds)->delete();

        DB::table('team_address')
            ->whereIn('team_id', $teamIds)
            ->whereNotIn('team_id', [2, 15])
            ->delete();
    }
};
