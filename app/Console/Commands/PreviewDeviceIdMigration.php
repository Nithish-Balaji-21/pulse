<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PreviewDeviceIdMigration extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'device:preview-id-migration';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Preview the device ID migration - shows old IDs and what new IDs they will become';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('=== Device ID Migration Preview ===');
        $this->newLine();

        // Get all existing devices
        $devices = DB::table('pulse_devices')
            ->join('teams', 'pulse_devices.team_id', '=', 'teams.id')
            ->select('pulse_devices.device_id', 'pulse_devices.team_id', 'teams.name as team_name', 'pulse_devices.module', 'pulse_devices.status')
            ->orderBy('pulse_devices.device_id')
            ->get();

        if ($devices->isEmpty()) {
            $this->warn('No devices found in the database.');

            return 0;
        }

        $this->info("Found {$devices->count()} devices");
        $this->newLine();

        // Create mapping
        $idMapping = [];
        $newId = 1;

        foreach ($devices as $device) {
            $oldId = $device->device_id;

            while (in_array($newId, $idMapping) && $newId <= 1000) {
                $newId++;
            }

            if ($newId > 1000) {
                $this->error('ERROR: More than 1000 devices exist. Cannot fit all devices in range 1-1000.');
                $this->error('Please increase the ID range in the code before migrating.');

                return 1;
            }

            $idMapping[$oldId] = $newId;
            $newId++;
        }

        // Display the mapping
        $this->table(
            ['Old Device ID', 'New Device ID', 'Team', 'Module', 'Status'],
            collect($devices)->map(function ($device) use ($idMapping) {
                return [
                    $device->device_id,
                    $idMapping[$device->device_id],
                    $device->team_name,
                    $device->module,
                    $device->status,
                ];
            })
        );

        $this->newLine();

        // Count related records
        $this->info('=== Related Records Count ===');
        $tables = [
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

        $relatedData = [];
        foreach ($tables as $table => $column) {
            if (\Schema::hasTable($table) && \Schema::hasColumn($table, $column)) {
                $count = DB::table($table)->count();
                if ($count > 0) {
                    $relatedData[] = [$table, number_format($count)];
                }
            }
        }

        if (! empty($relatedData)) {
            $this->table(['Table Name', 'Record Count'], $relatedData);
        }

        $this->newLine();
        $this->warn('⚠️  IMPORTANT: This is just a preview. No changes have been made.');
        $this->newLine();
        $this->info('To apply these changes, run:');
        $this->line('  php artisan migrate');
        $this->newLine();

        return 0;
    }
}
