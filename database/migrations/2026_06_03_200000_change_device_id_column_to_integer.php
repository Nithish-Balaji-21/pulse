<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Step 2: Change device_id column type from bigint/varchar to integer
     * This runs AFTER the data migration
     */
    public function up(): void
    {
        echo 'Starting device_id schema migration...'.PHP_EOL;

        // Disable foreign key checks temporarily
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');

        try {
            // 1. Modify the devices table (primary key)
            if (Schema::hasTable('devices')) {
                echo 'Updating devices table...'.PHP_EOL;

                // Drop any foreign key constraints first
                Schema::table('devices', function (Blueprint $table) {
                    // Change to unsigned integer
                    $table->unsignedInteger('device_id')->change();
                });

                echo '✓ devices table updated'.PHP_EOL;
            }

            // 2. Modify all tables that reference device_id
            $tables = [
                'device_data',
                'device_data_fibre_bales',
                'device_data_fibre_mega_bales_weight',
                'device_data_growbags',
                'device_data_pith_blocks',
                'device_data_states',
                'fibre_productions',
                'master_growbag_machines',
                'master_water_meters',
                'master_water_tanks',
                'pith_blocks_productions',
                'pith_productions',
                'water_meter_consumptions',
                'summary_power_states',
            ];

            foreach ($tables as $tableName) {
                if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'device_id')) {
                    echo "Updating {$tableName}...".PHP_EOL;

                    Schema::table($tableName, function (Blueprint $table) {
                        $table->unsignedInteger('device_id')->change();
                    });

                    echo "✓ {$tableName} updated".PHP_EOL;
                }
            }

            echo '✓ All device_id columns changed to integer successfully'.PHP_EOL;

        } catch (Exception $e) {
            echo 'ERROR: Schema migration failed: '.$e->getMessage().PHP_EOL;
            throw $e;
        } finally {
            // Re-enable foreign key checks
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        echo 'Reverting device_id schema changes...'.PHP_EOL;

        // Disable foreign key checks temporarily
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');

        try {
            // 1. Revert the devices table (primary key)
            if (Schema::hasTable('devices')) {
                Schema::table('devices', function (Blueprint $table) {
                    $table->bigInteger('device_id')->change();
                });
            }

            // 2. Revert all tables that reference device_id
            $tables = [
                'device_data',
                'device_data_fibre_bales',
                'device_data_fibre_mega_bales_weight',
                'device_data_growbags',
                'device_data_pith_blocks',
                'device_data_states',
                'fibre_productions',
                'master_growbag_machines',
                'master_water_meters',
                'master_water_tanks',
                'pith_blocks_productions',
                'pith_productions',
                'water_meter_consumptions',
                'summary_power_states',
            ];

            foreach ($tables as $tableName) {
                if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'device_id')) {
                    Schema::table($tableName, function (Blueprint $table) {
                        $table->bigInteger('device_id')->change();
                    });
                }
            }

            echo '✓ Schema rollback completed'.PHP_EOL;

        } finally {
            // Re-enable foreign key checks
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        }
    }
};
