<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Corrective pass for databases that already ran the initial team schema migrations.
     */
    public function up(): void
    {
        $teams = DB::table('teams')->get();

        foreach ($teams as $team) {
            $factoryAddress = DB::table('team_address')
                ->where('team_id', $team->id)
                ->where('type', 'factory')
                ->first();

            if (! $factoryAddress) {
                $factoryAddressId = DB::table('team_address')->insertGetId([
                    'team_id' => $team->id,
                    'type' => 'factory',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $factoryAddressId = $factoryAddress->id;
            }

            $pulse = DB::table('team_pulse')->where('team_id', $team->id)->first();

            if ($pulse && empty($pulse->factory_address_id)) {
                DB::table('team_pulse')
                    ->where('team_id', $team->id)
                    ->update(['factory_address_id' => $factoryAddressId]);
            }

            $products = json_decode($team->products ?? '{}', true) ?? [];
            $access = [];
            if ($products['pulse'] ?? false) {
                $access[] = 'pulse';
            }
            if (empty($access)) {
                $access = ['pulse'];
            }

            DB::table('team_user')
                ->where('team_id', $team->id)
                ->update(['product_access' => json_encode($access)]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Non-destructive corrective migration.
    }
};
