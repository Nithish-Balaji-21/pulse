<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Step 1: Update existing device ID values from large numbers to 1-1000 range
     * This MUST run before the schema change migration
     */
    public function up(): void
    {
        $this->info('Starting device ID data migration...');

        // Get all existing devices with their large IDs
        $devices = DB::table('devices')->orderBy('device_id')->get();

        if ($devices->isEmpty()) {
            $this->info('No devices to migrate.');

            return;
        }

        $this->info("Found {$devices->count()} devices to migrate");

        // Create a mapping of old IDs to new IDs
        $idMapping = [];
        $newId = 1;

        foreach ($devices as $device) {
            $oldId = $device->device_id;

            // Skip if already in range 1-1000
            if ($oldId >= 1 && $oldId <= 1000) {
                continue;
            }

            // Find an available ID in the range 1-1000
            while (isset($idMapping[$newId]) || DB::table('devices')->where('device_id', $newId)->exists()) {
                $newId++;
                if ($newId > 1000) {
                    throw new Exception('Cannot migrate: Cannot assign unique IDs within range 1-1000. Consider increasing the range.');
                }
            }

            $idMapping[$oldId] = $newId;
            $newId++;
        }

        if (empty($idMapping)) {
            $this->info('All device IDs are already in the correct range (1-1000). No migration needed.');

            return;
        }

        $this->info('ID Mapping created for '.count($idMapping).' devices');

        // Temporarily disable foreign key checks
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');

        try {
            // Tables that need updating (in order to avoid conflicts)
            $tablesToUpdate = [
                'device_data' => 'device_id',
                'device_data_fibre_bales' => 'device_id',
                'device_data_fibre_mega_bales_weight' => 'device_id',
                'device_data_growbags' => 'device_id',
                'device_data_pith_blocks' => 'device_id',
                'device_data_states' => 'device_id',
                'fibre_productions' => 'device_id',
                'master_growbag_machines' => 'device_id',
                'master_water_meters' => 'device_id',
                'master_water_tanks' => 'device_id',
                'pith_blocks_productions' => 'device_id',
                'pith_productions' => 'device_id',
                'water_meter_consumptions' => 'device_id',
                'summary_power_states' => 'device_id',
            ];

            // Update each device ID
            foreach ($idMapping as $oldId => $newId) {
                // Use a temporary negative ID to avoid conflicts
                $tempId = -($newId);

                // Step 1: Update devices table to temp ID
                DB::table('devices')
                    ->where('device_id', $oldId)
                    ->update(['device_id' => $tempId]);

                // Step 2: Update all related tables to temp ID
                foreach ($tablesToUpdate as $table => $column) {
                    if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                        $count = DB::table($table)->where($column, $oldId)->count();
                        if ($count > 0) {
                            DB::table($table)
                                ->where($column, $oldId)
                                ->update([$column => $tempId]);
                            $this->info("  Updated {$count} records in {$table}");
                        }
                    }
                }
            }

            // Step 3: Convert all temp IDs to final IDs
            foreach ($idMapping as $oldId => $newId) {
                $tempId = -($newId);

                // Update devices table
                DB::table('devices')
                    ->where('device_id', $tempId)
                    ->update(['device_id' => $newId]);

                // Update all related tables
                foreach ($tablesToUpdate as $table => $column) {
                    if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                        DB::table($table)
                            ->where($column, $tempId)
                            ->update([$column => $newId]);
                    }
                }

                $this->info("Migrated device_id: {$oldId} -> {$newId}");
            }

            // Log the complete mapping
            $mappingJson = json_encode($idMapping, JSON_PRETTY_PRINT);
            Log::info('Device ID Data Migration Completed. Mapping: '.$mappingJson);
            $this->info('✓ Device ID data migration completed successfully');

        } catch (Exception $e) {
            $this->error('Migration failed: '.$e->getMessage());
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
        $this->warn('⚠️  Rollback of device ID data migration is not supported.');
        $this->warn('Restore from database backup if you need to revert this change.');
    }

    /**
     * Helper method to output info during migration
     */
    private function info($message)
    {
        echo $message.PHP_EOL;
    }

    /**
     * Helper method to output warnings during migration
     */
    private function warn($message)
    {
        echo 'WARNING: '.$message.PHP_EOL;
    }

    /**
     * Helper method to output errors during migration
     */
    private function error($message)
    {
        echo 'ERROR: '.$message.PHP_EOL;
    }
};
