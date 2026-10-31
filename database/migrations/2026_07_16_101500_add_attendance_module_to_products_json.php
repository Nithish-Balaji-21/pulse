<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $teams = DB::table('teams')->get();

        foreach ($teams as $team) {
            $products = json_decode($team->products, true) ?? [];

            // Add attendance module to pulse -> modules
            if (! isset($products['pulse']['modules']['attendance'])) {
                $products['pulse']['modules']['attendance'] = [
                    'is_active' => true, // default to true for existing teams
                ];

                DB::table('teams')
                    ->where('id', $team->id)
                    ->update(['products' => json_encode($products)]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $teams = DB::table('teams')->get();

        foreach ($teams as $team) {
            $products = json_decode($team->products, true) ?? [];

            if (isset($products['pulse']['modules']['attendance'])) {
                unset($products['pulse']['modules']['attendance']);

                DB::table('teams')
                    ->where('id', $team->id)
                    ->update(['products' => json_encode($products)]);
            }
        }
    }
};
