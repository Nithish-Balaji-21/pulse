<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var array<string, string>
     */
    private array $tables = [
        'devices' => 'pulse_devices',
        'device_data' => 'pulse_device_data',
        'device_data_daily_summary' => 'pulse_device_data_daily_summary',
        'device_data_fibre_bales' => 'pulse_device_data_fibre_bales',
        'device_data_fibre_bales_weight' => 'pulse_device_data_fibre_bales_weight',
        'device_data_fibre_mega_bales_weight' => 'pulse_device_data_fibre_mega_bales_weight',
        'device_data_growbag' => 'pulse_device_data_growbag',
        'device_data_growbags' => 'pulse_device_data_growbags',
        'device_data_pith_blocks' => 'pulse_device_data_pith_blocks',
        'device_data_state' => 'pulse_device_data_state',
        'device_data_water_meters' => 'pulse_device_data_water_meters',
        'device_data_water_tank_levels' => 'pulse_device_data_water_tank_levels',
        'fibre_productions' => 'pulse_fibre_productions',
        'fibre_minibale_productions' => 'pulse_fibre_minibale_productions',
        'fibrebale_machines' => 'pulse_fibrebale_machines',
        'master_fibrebale_machines' => 'pulse_master_fibrebale_machines',
        'growbag_productions' => 'pulse_growbag_productions',
        'growbag_machines' => 'pulse_growbag_machines',
        'master_growbag_machines' => 'pulse_master_growbag_machines',
        'pith_productions' => 'pulse_pith_productions',
        'pith_blocks_productions' => 'pulse_pith_blocks_productions',
        'pith_loads' => 'pulse_pith_loads',
        'pith_locations' => 'pulse_pith_locations',
        'pith_units' => 'pulse_pith_units',
        'pith_stock_depletions' => 'pulse_pith_stock_depletions',
        'pith_inventories' => 'pulse_pith_inventories',
        'husks_daily_stocks' => 'pulse_husks_daily_stocks',
        'husks_daily_stock' => 'pulse_husks_daily_stock',
        'master_water_meters' => 'pulse_master_water_meters',
        'master_water_tanks' => 'pulse_master_water_tanks',
        'water_meter_consumptions' => 'pulse_water_meter_consumptions',
        'weather_data_daily' => 'pulse_weather_data_daily',
        'weather_data_hourly' => 'pulse_weather_data_hourly',
        'weather_rules' => 'pulse_weather_rules',
        'assets' => 'pulse_assets',
        'asset_categories' => 'pulse_asset_categories',
        'asset_outwards' => 'pulse_asset_outwards',
        'asset_purchases' => 'pulse_asset_purchases',
        'asset_returns' => 'pulse_asset_returns',
        'product_purchases' => 'pulse_product_purchases',
        'product_purchase' => 'pulse_product_purchase',
        'projects' => 'pulse_projects',
        'shifts' => 'pulse_shifts',
        'attendances' => 'pulse_attendances',
        'attendance' => 'pulse_attendance',
        'expenses' => 'pulse_expenses',
        'expense_categories' => 'pulse_expense_categories',
        'team_color_settings' => 'pulse_team_color_settings',
        'summary_fibre_mini_bales' => 'pulse_summary_fibre_mini_bales',
        'summary_fibre_mega_bales' => 'pulse_summary_fibre_mega_bales',
        'summary_growbags' => 'pulse_summary_growbags',
        'summary_pith_blocks' => 'pulse_summary_pith_blocks',
        'summary_pith_productions' => 'pulse_summary_pith_productions',
        'summary_power_states' => 'pulse_summary_power_states',
        'summary_water_meter' => 'pulse_summary_water_meter',
    ];

    public function up(): void
    {
        foreach ($this->tables as $from => $to) {
            $this->renameIfPossible($from, $to);
        }

        Schema::table('teams', function (Blueprint $table) {
            if (! Schema::hasColumn('teams', 'features')) {
                $table->json('features')
                    ->nullable()
                    ->after('name');
            }
        });

        DB::table('teams')
            ->whereNull('features')
            ->update(['features' => json_encode(['pulse' => true])]);
    }

    public function down(): void
    {
        foreach (array_reverse($this->tables) as $from => $to) {
            $this->renameIfPossible($to, $from);
        }

        Schema::table('teams', function (Blueprint $table) {
            if (Schema::hasColumn('teams', 'features')) {
                $table->dropColumn('features');
            }
        });
    }

    private function renameIfPossible(string $from, string $to): void
    {
        if (Schema::hasTable($from) && ! Schema::hasTable($to)) {
            Schema::rename($from, $to);
        }
    }
};
