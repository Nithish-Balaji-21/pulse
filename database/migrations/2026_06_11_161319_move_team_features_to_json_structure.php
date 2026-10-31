<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->json('products')->nullable()->after('has_growbags_module');
        });

        $teams = DB::table('teams')->get();
        foreach ($teams as $team) {
            $products = [
                'pulse' => [
                    'is_active' => true,
                    'modules' => [
                        'pith' => [
                            'is_active' => (bool) ($team->has_pith_module ?? false),
                            'submodules' => [
                                'blocks' => (bool) ($team->has_pith_module_blocks ?? false),
                                'bales' => (bool) ($team->has_pith_module_bales ?? false),
                            ],
                        ],
                        'fibre' => [
                            'is_active' => (bool) ($team->has_fibre_module ?? false),
                            'submodules' => [
                                'weight' => (bool) ($team->has_fibre_module_weight ?? false),
                            ],
                        ],
                        'growbags' => [
                            'is_active' => (bool) ($team->has_growbags_module ?? false),
                        ],
                        'husks_stock' => [
                            'is_active' => (bool) ($team->has_husks_stock_module ?? false),
                        ],
                        'water_meter' => [
                            'is_active' => (bool) ($team->has_water_meter_module ?? false),
                        ],
                    ],
                ],
            ];

            DB::table('teams')->where('id', $team->id)->update([
                'products' => json_encode($products),
            ]);
        }

        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn([
                'has_pith_module',
                'has_pith_module_blocks',
                'has_pith_module_bales',
                'has_fibre_module',
                'has_fibre_module_weight',
                'has_growbags_module',
                'has_husks_stock_module',
                'has_water_meter_module',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->boolean('has_pith_module')->default(0);
            $table->boolean('has_pith_module_blocks')->default(0);
            $table->boolean('has_pith_module_bales')->default(0);
            $table->boolean('has_fibre_module')->default(0);
            $table->boolean('has_fibre_module_weight')->default(0);
            $table->boolean('has_growbags_module')->default(0);
            $table->boolean('has_husks_stock_module')->default(0);
            $table->boolean('has_water_meter_module')->default(0);
        });

        $teams = DB::table('teams')->get();
        foreach ($teams as $team) {
            if ($team->products) {
                $products = json_decode($team->products, true);

                $pulseModules = $products['pulse']['modules'] ?? [];

                DB::table('teams')->where('id', $team->id)->update([
                    'has_pith_module' => $pulseModules['pith']['is_active'] ?? false,
                    'has_pith_module_blocks' => $pulseModules['pith']['submodules']['blocks'] ?? false,
                    'has_pith_module_bales' => $pulseModules['pith']['submodules']['bales'] ?? false,
                    'has_fibre_module' => $pulseModules['fibre']['is_active'] ?? false,
                    'has_fibre_module_weight' => $pulseModules['fibre']['submodules']['weight'] ?? false,
                    'has_growbags_module' => $pulseModules['growbags']['is_active'] ?? false,
                    'has_husks_stock_module' => $pulseModules['husks_stock']['is_active'] ?? false,
                    'has_water_meter_module' => $pulseModules['water_meter']['is_active'] ?? false,
                ]);
            }
        }

        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('products');
        });
    }
};
