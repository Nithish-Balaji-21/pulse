<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // All tables needing DEFAULT + type fix on both columns
    private array $fixBothTimestamps = [
        'assets',
        'asset_outwards',
        'asset_purchases',
        'asset_returns',
        'attendance',
        'device_data_daily_summary',
        'pulse_device_data_vehicles_telemetry',
        'expenses',
        'projects',
        'pulse_husks_daily_stock',
        'pith_loads',
        'pith_locations',
        'pith_stock_depletions',
        'pith_units',
        'settings',
        'shifts',
        'staffs',
        'summary_fibre_mega_bales',
        'summary_fibre_mini_bales',
        'summary_growbags',
        'summary_pith_blocks',
        'summary_pith_productions',
        'summary_power_states',
        'summary_water_meter',
        'projects',
    ];

    public function up(): void
    {
        foreach ($this->fixBothTimestamps as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            if (Schema::hasColumn($table, 'created_at')) {
                DB::statement(
                    "ALTER TABLE `{$table}`
                     MODIFY `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP"
                );
            }

            if (Schema::hasColumn($table, 'updated_at')) {
                DB::statement(
                    "ALTER TABLE `{$table}`
                     MODIFY `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
                     ON UPDATE CURRENT_TIMESTAMP"
                );
            }
        }
    }

    public function down(): void
    {
        foreach ($this->fixBothTimestamps as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            if (Schema::hasColumn($table, 'created_at')) {
                DB::statement(
                    "ALTER TABLE `{$table}` MODIFY `created_at` DATETIME NULL"
                );
            }

            if (Schema::hasColumn($table, 'updated_at')) {
                DB::statement(
                    "ALTER TABLE `{$table}` MODIFY `updated_at` DATETIME NULL"
                );
            }
        }
    }
};
