<?php

namespace App\Console\Commands\Pulse;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CheckDeviceIdStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'device:check-status';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check current device ID status and determine if migration is needed';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('=== Device ID Status Check ===');
        $this->newLine();

        // Check if pulse_devices table exists
        if (! Schema::hasTable('pulse_devices')) {
            $this->error('Devices table does not exist!');

            return 1;
        }

        // Get device count
        $deviceCount = DB::table('pulse_devices')->count();
        $this->info("Total pulse_devices: {$deviceCount}");

        if ($deviceCount === 0) {
            $this->warn('No pulse_devices found. Migration not needed.');

            return 0;
        }

        // Get min and max device IDs
        $minId = DB::table('pulse_devices')->min('device_id');
        $maxId = DB::table('pulse_devices')->max('device_id');

        $this->info("Device ID range: {$minId} to {$maxId}");
        $this->newLine();

        // Check if migration is needed
        $needsMigration = false;
        $devicesOutOfRange = DB::table('pulse_devices')
            ->where(function ($query) {
                $query->where('device_id', '<', 1)
                    ->orWhere('device_id', '>', 1000);
            })
            ->count();

        if ($devicesOutOfRange > 0) {
            $needsMigration = true;
            $this->warn("⚠️  {$devicesOutOfRange} device(s) have IDs outside the range 1-1000");
        }

        // Check column type
        $columns = DB::select("SHOW COLUMNS FROM pulse_devices WHERE Field = 'device_id'");
        if (! empty($columns)) {
            $columnType = $columns[0]->Type;
            $this->info("Device ID column type: {$columnType}");

            if (stripos($columnType, 'bigint') !== false || stripos($columnType, 'varchar') !== false) {
                $needsMigration = true;
                $this->warn('⚠️  Column type needs to be changed to integer');
            } elseif (stripos($columnType, 'int') !== false) {
                $this->info('✓ Column type is already integer');
            }
        }

        $this->newLine();

        // Sample pulse_devices
        $this->info('=== Sample Devices ===');
        $sampleDevices = DB::table('pulse_devices')
            ->join('teams', 'pulse_devices.team_id', '=', 'teams.id')
            ->select('pulse_devices.device_id', 'teams.name as team_name', 'pulse_devices.module', 'pulse_devices.status')
            ->limit(5)
            ->get();

        $this->table(
            ['Device ID', 'Team', 'Module', 'Status'],
            $sampleDevices->map(function ($device) {
                return [
                    $device->device_id,
                    $device->team_name,
                    $device->module,
                    $device->status,
                ];
            })
        );

        $this->newLine();

        // Final recommendation
        if ($needsMigration) {
            $this->warn('🔔 MIGRATION RECOMMENDED');
            $this->newLine();
            $this->line('Your device IDs need to be migrated to the 1-1000 range.');
            $this->line('');
            $this->line('Next steps:');
            $this->line('  1. Backup your database');
            $this->line('  2. Run: php artisan device:preview-id-migration');
            $this->line('  3. Run: php artisan migrate');
            $this->newLine();
        } else {
            $this->info('✅ ALL GOOD - No migration needed');
            $this->line('All device IDs are already in the correct range (1-1000)');
            $this->newLine();
        }

        return 0;
    }
}
