<?php

namespace Database\Seeders;

use App\Support\Pulse\GeofencingModule;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * DemoScaffoldSeeder — Creates core users, 3 factory teams, admin/staff access, reference settings,
 * yard locations, water meters/tanks, and master machines.
 *
 * Must run BEFORE DemoDataSeeder.
 * Run: php artisan db:seed --class=DemoScaffoldSeeder --force
 */
class DemoScaffoldSeeder extends Seeder
{
    /** Default password for factory admins and staff */
    private const DEFAULT_PASSWORD = 'democoiiot';

    private const SUPERADMIN_PASSWORD = 'Sandbox@123';

    public function run(): void
    {
        $this->command->info('====================================================');
        $this->command->info('  DemoScaffoldSeeder: Initializing Factory System...');
        $this->command->info('====================================================');

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            // Factory Team & Device IDs from configuration or fallback
            $fibreTeamId = (int) config('demo.fibre_team_id', 1);
            $pithTeamId = (int) config('demo.pith_team_id', 2);
            $growbagsTeamId = (int) config('demo.growbags_team_id', 3);

            $fibreDeviceId = (int) config('demo.fibre_device_id', 1);
            $pithDeviceId = (int) config('demo.pith_device_id', 2);
            $growbagsDeviceId = (int) config('demo.growbags_device_id', 3);

            // 1. Create Superadmin User
            $superadminId = $this->createSuperAdmin();

            // 2. Create Factory Admins (1 per factory)
            $adminIds = $this->createFactoryAdmins($fibreTeamId, $pithTeamId, $growbagsTeamId);

            // 3. Create Staff Members (10 staff across 3 factories)
            $staffAssignments = $this->createStaffUsers($fibreTeamId, $pithTeamId, $growbagsTeamId);

            // 4. Create 3 Factory Teams
            $this->createFactoryTeams($fibreTeamId, $pithTeamId, $growbagsTeamId, $adminIds);

            // 5. Assign primary factory team to Superadmin
            DB::table('users')->where('id', $superadminId)->update(['current_team_id' => $fibreTeamId]);

            // 6. Link Users to Teams with roles (Admin vs Member)
            $this->linkUsersToTeams($superadminId, $adminIds, $staffAssignments, $fibreTeamId, $pithTeamId, $growbagsTeamId);

            // 7. Stamp Existing Database Migrations
            $this->stampMigrations();

            // 8. Seed Pulse Settings (Tariffs, Conversions, Anchors)
            $this->seedPulseSettings($fibreTeamId, $pithTeamId, $growbagsTeamId);

            // 9. Seed Operating Shifts per Factory
            $this->seedPulseShifts($fibreTeamId, $pithTeamId, $growbagsTeamId);

            // 10. Seed Pulse Devices per Factory (Pith, Fibre, GrowBags)
            $this->seedPulseDevices($fibreTeamId, $pithTeamId, $growbagsTeamId, $fibreDeviceId, $pithDeviceId, $growbagsDeviceId);

            // 11. Seed Staff Members in staffs table
            $this->seedStaffsTable($fibreTeamId, $pithTeamId, $growbagsTeamId);

            // 12. Seed Pith Yard Locations
            $this->seedPithLocations($pithTeamId);

            // 13. Seed Master Water Meters & Water Tanks
            $this->seedMasterWaterMetersAndTanks($pithTeamId, $pithDeviceId);

            // 14. Seed Master GrowBag Machines
            $this->seedMasterGrowbagMachines($growbagsTeamId, $growbagsDeviceId);

            $this->command->info(PHP_EOL.'✔ DemoScaffoldSeeder Completed Successfully!');
            $this->command->info('----------------------------------------------------');
            $this->command->info('  Superadmin Access : imikjagadeesan@gmail.com / '.self::SUPERADMIN_PASSWORD);
            $this->command->info('  Factory Admins    : fibreadmin@coiiot.in, pithadmin@coiiot.in, growbagadmin@coiiot.in');
            $this->command->info('  Admins Password   : '.self::DEFAULT_PASSWORD);
            $this->command->info("  Factories Created : ID {$fibreTeamId} (Fibre), ID {$pithTeamId} (Pith), ID {$growbagsTeamId} (GrowBags)");
            $this->command->info('----------------------------------------------------');

        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    /**
     * Step 1: Create 1 Superadmin User
     */
    private function createSuperAdmin(): int
    {
        $this->command->info('  [1/14] Creating Superadmin account...');

        DB::table('users')->truncate();

        $superadminId = DB::table('users')->insertGetId([
            'name' => 'Super Admin',
            'email' => 'imikjagadeesan@gmail.com',
            'password' => Hash::make(self::SUPERADMIN_PASSWORD),
            'is_superadmin' => 1,
            'is_active' => 1,
            'email_verified_at' => now(),
            'current_team_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->command->info("        -> Superadmin created (ID: {$superadminId})");

        return $superadminId;
    }

    /**
     * Step 2: Create 3 Factory Admin Users (1 per factory)
     *
     * @return array<string, int> Map of factory key to user ID
     */
    private function createFactoryAdmins(int $fibreTeamId, int $pithTeamId, int $growbagsTeamId): array
    {
        $this->command->info('  [2/14] Creating Factory Admin accounts (3 Admins)...');

        $hashedPassword = Hash::make(self::DEFAULT_PASSWORD);

        $fibreAdminId = DB::table('users')->insertGetId([
            'name' => 'Fibre Admin',
            'email' => 'fibreadmin@coiiot.in',
            'password' => $hashedPassword,
            'is_superadmin' => 0,
            'is_active' => 1,
            'email_verified_at' => now(),
            'current_team_id' => $fibreTeamId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $pithAdminId = DB::table('users')->insertGetId([
            'name' => 'Pith Admin',
            'email' => 'pithadmin@coiiot.in',
            'password' => $hashedPassword,
            'is_superadmin' => 0,
            'is_active' => 1,
            'email_verified_at' => now(),
            'current_team_id' => $pithTeamId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $growbagsAdminId = DB::table('users')->insertGetId([
            'name' => 'GrowBag Admin',
            'email' => 'growbagadmin@coiiot.in',
            'password' => $hashedPassword,
            'is_superadmin' => 0,
            'is_active' => 1,
            'email_verified_at' => now(),
            'current_team_id' => $growbagsTeamId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->command->info("        -> Fibre Factory Admin ID    : {$fibreAdminId}");
        $this->command->info("        -> Pith Factory Admin ID     : {$pithAdminId}");
        $this->command->info("        -> GrowBags Factory Admin ID : {$growbagsAdminId}");

        return [
            'fibre' => $fibreAdminId,
            'pith' => $pithAdminId,
            'growbags' => $growbagsAdminId,
        ];
    }

    /**
     * Step 3: Create Staff Members across the 3 factories
     *
     * @return array<int, array{user_id: int, team_id: int}>
     */
    private function createStaffUsers(int $fibreTeamId, int $pithTeamId, int $growbagsTeamId): array
    {
        $this->command->info('  [3/14] Creating Staff accounts (10 Staff users)...');

        $staffList = [
            // Fibre Factory Staff (3 users)
            ['name' => 'Rajesh Kumar',   'email' => 'rajesh.fibre@demo.com', 'team_id' => $fibreTeamId],
            ['name' => 'Suresh Babu',    'email' => 'suresh.fibre@demo.com', 'team_id' => $fibreTeamId],
            ['name' => 'Manoj Reddy',    'email' => 'manoj.fibre@demo.com',  'team_id' => $fibreTeamId],

            // Pith Factory Staff (4 users)
            ['name' => 'Arun Selvam',    'email' => 'arun.pith@demo.com',    'team_id' => $pithTeamId],
            ['name' => 'Ganesan Pillai', 'email' => 'ganesan.pith@demo.com', 'team_id' => $pithTeamId],
            ['name' => 'Murugan Das',    'email' => 'murugan.pith@demo.com', 'team_id' => $pithTeamId],
            ['name' => 'Vijay Kumar',    'email' => 'vijay.pith@demo.com',   'team_id' => $pithTeamId],

            // GrowBags Factory Staff (3 users)
            ['name' => 'Karthik Raj',    'email' => 'karthik.gb@demo.com',   'team_id' => $growbagsTeamId],
            ['name' => 'Senthil Nathan', 'email' => 'senthil.gb@demo.com',  'team_id' => $growbagsTeamId],
            ['name' => 'Pradeep Cocos',  'email' => 'pradeep.gb@demo.com',   'team_id' => $growbagsTeamId],
        ];

        $hashedPassword = Hash::make(self::DEFAULT_PASSWORD);
        $assignments = [];

        foreach ($staffList as $staff) {
            $userId = DB::table('users')->insertGetId([
                'name' => $staff['name'],
                'email' => $staff['email'],
                'password' => $hashedPassword,
                'is_superadmin' => 0,
                'is_active' => 1,
                'email_verified_at' => now(),
                'current_team_id' => $staff['team_id'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $assignments[] = [
                'user_id' => $userId,
                'team_id' => $staff['team_id'],
            ];
        }

        $this->command->info('        -> Total staff users created: '.count($assignments));

        return $assignments;
    }

    /**
     * Step 4: Create 3 Factory Teams with module enablement configuration
     */
    private function createFactoryTeams(int $fibreId, int $pithId, int $growbagsId, array $adminIds): void
    {
        $this->command->info('  [4/14] Creating 3 Factory Teams...');

        DB::table('teams')->truncate();
        DB::table('team_user')->truncate();

        $fibreProducts = json_encode([
            'pulse' => [
                'is_active' => true,
                'modules' => [
                    'fibre' => ['is_active' => true, 'submodules' => ['weight' => true]],
                    'water_meter' => ['is_active' => false],
                    'water_tank' => ['is_active' => false],
                    'expenses' => ['is_active' => true],
                    'attendance' => ['is_active' => true],
                    'geofencing' => ['is_active' => false, 'max_boundaries' => GeofencingModule::defaultMaxBoundaries()],
                ],
            ],
        ]);

        $pithProducts = json_encode([
            'pulse' => [
                'is_active' => true,
                'modules' => [
                    'pith' => ['is_active' => true, 'submodules' => ['bales' => true, 'blocks' => true]],
                    'water_meter' => ['is_active' => true],
                    'water_tank' => ['is_active' => true],
                    'expenses' => ['is_active' => true],
                    'attendance' => ['is_active' => true],
                    'geofencing' => ['is_active' => false, 'max_boundaries' => GeofencingModule::defaultMaxBoundaries()],
                ],
            ],
        ]);

        $growbagsProducts = json_encode([
            'pulse' => [
                'is_active' => true,
                'modules' => [
                    'growbags' => ['is_active' => true],
                    'water_meter' => ['is_active' => false],
                    'water_tank' => ['is_active' => false],
                    'expenses' => ['is_active' => true],
                    'attendance' => ['is_active' => true],
                    'geofencing' => ['is_active' => false, 'max_boundaries' => GeofencingModule::defaultMaxBoundaries()],
                ],
            ],
        ]);

        $licenseDate = Carbon::today()->addYear()->toDateString();
        $installedDate = Carbon::today()->subMonths(6)->toDateString();

        DB::statement('ALTER TABLE teams AUTO_INCREMENT = 1');

        DB::table('teams')->insert([
            [
                'id' => $fibreId,
                'user_id' => $adminIds['fibre'],
                'name' => 'Demo Fibre Factory',
                'personal_team' => 0,
                'products' => $fibreProducts,
                'coordinates' => '10.724877,77.080572',
                'is_active' => 1,
                'license_valid' => $licenseDate,
                'installed_date' => $installedDate,
                'software_only' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $pithId,
                'user_id' => $adminIds['pith'],
                'name' => 'Demo Pith Factory',
                'personal_team' => 0,
                'products' => $pithProducts,
                'coordinates' => '10.8128168,77.1014343',
                'is_active' => 1,
                'license_valid' => $licenseDate,
                'installed_date' => $installedDate,
                'software_only' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $growbagsId,
                'user_id' => $adminIds['growbags'],
                'name' => 'Demo GrowBags Factory',
                'personal_team' => 0,
                'products' => $growbagsProducts,
                'coordinates' => '10.822390,77.136308',
                'is_active' => 1,
                'license_valid' => $licenseDate,
                'installed_date' => $installedDate,
                'software_only' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $nextAutoInc = max($fibreId, $pithId, $growbagsId) + 1;
        DB::statement("ALTER TABLE teams AUTO_INCREMENT = {$nextAutoInc}");

        $this->command->info("        -> 3 Factory Teams created (IDs: {$fibreId}, {$pithId}, {$growbagsId})");
    }

    /**
     * Step 5: Link Users to Teams with Roles ('admin' vs 'member')
     */
    private function linkUsersToTeams(
        int $superadminId,
        array $adminIds,
        array $staffAssignments,
        int $fibreId,
        int $pithId,
        int $growbagsId
    ): void {
        $this->command->info('  [5/14] Linking users to factory teams...');

        $teamUserRows = [
            // Factory Admins (admin role in their respective factory)
            ['team_id' => $fibreId,    'user_id' => $adminIds['fibre'],    'role' => 'admin'],
            ['team_id' => $pithId,     'user_id' => $adminIds['pith'],     'role' => 'admin'],
            ['team_id' => $growbagsId, 'user_id' => $adminIds['growbags'], 'role' => 'admin'],

            // Superadmin (admin role in ALL 3 factories)
            ['team_id' => $fibreId,    'user_id' => $superadminId,        'role' => 'admin'],
            ['team_id' => $pithId,     'user_id' => $superadminId,        'role' => 'admin'],
            ['team_id' => $growbagsId, 'user_id' => $superadminId,        'role' => 'admin'],
        ];

        // Staff members (member role in their assigned factory)
        foreach ($staffAssignments as $staff) {
            $teamUserRows[] = [
                'team_id' => $staff['team_id'],
                'user_id' => $staff['user_id'],
                'role' => 'member',
            ];
        }

        $now = now();
        foreach ($teamUserRows as &$row) {
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
        }
        unset($row);

        DB::table('team_user')->insert($teamUserRows);
        $this->command->info('        -> Total team-user links created: '.count($teamUserRows));
    }

    /**
     * Step 6: Stamp migration table to mark existing migrations as executed
     */
    private function stampMigrations(): void
    {
        $this->command->info('  [6/14] Stamping database migrations table...');

        $migrationFiles = glob(database_path('migrations/*.php'));
        sort($migrationFiles);

        $rows = [];
        foreach ($migrationFiles as $path) {
            $rows[] = [
                'migration' => basename($path, '.php'),
                'batch' => 1,
            ];
        }

        if (! empty($rows)) {
            DB::table('migrations')->upsert($rows, ['migration'], ['batch']);
        }

        $this->command->info('        -> Stamped '.count($rows).' migration files');
    }

    /**
     * Step 7: Seed default Pulse settings (Tariff, Conversion, Shift Anchor)
     */
    private function seedPulseSettings(int $fibreId, int $pithId, int $growbagsId): void
    {
        $this->command->info('  [7/14] Seeding Pulse settings...');

        $settings = [
            // Fibre Settings
            ['team_id' => $fibreId,    'module' => 'fibre',   'key' => 'RS_PER_EB_UNIT',   'text' => 'Rupees per EB unit', 'value' => '10.15', 'options' => ''],
            ['team_id' => $fibreId,    'module' => 'shifts',  'key' => 'SHIFT_DAY_ANCHOR', 'text' => 'Shift day anchor',  'value' => '07:00:00', 'options' => null],

            // Pith Settings
            ['team_id' => $pithId,     'module' => 'pith',   'key' => 'RS_PER_EB_UNIT',   'text' => 'Rupees per EB unit', 'value' => '9.50', 'options' => ''],
            ['team_id' => $pithId,     'module' => 'pith',   'key' => 'EB_UNITS_PER_CFT', 'text' => 'EB units per CFT',  'value' => '0.318', 'options' => ''],
            ['team_id' => $pithId,     'module' => 'pith',   'key' => 'HUSKS_PER_CFT',    'text' => 'Husks per CFT',     'value' => '1.89', 'options' => ''],
            ['team_id' => $pithId,     'module' => 'pith',   'key' => 'PRODUCTION_UNIT',  'text' => 'Production Unit',   'value' => '300', 'options' => ''],
            ['team_id' => $pithId,     'module' => 'shifts',  'key' => 'SHIFT_DAY_ANCHOR', 'text' => 'Shift day anchor',  'value' => '08:00:00', 'options' => null],

            // GrowBags Settings
            ['team_id' => $growbagsId, 'module' => 'growbags', 'key' => 'RS_PER_EB_UNIT',   'text' => 'Rupees per EB unit', 'value' => '9.50', 'options' => ''],
            ['team_id' => $growbagsId, 'module' => 'shifts',  'key' => 'SHIFT_DAY_ANCHOR', 'text' => 'Shift day anchor',  'value' => '07:00:00', 'options' => null],
        ];

        $now = now();
        foreach ($settings as $s) {
            DB::table('pulse_settings')->updateOrInsert(
                ['team_id' => $s['team_id'], 'key' => $s['key']],
                array_merge($s, ['created_at' => $now, 'updated_at' => $now])
            );
        }

        $this->command->info('        -> Pulse settings configured');
    }

    /**
     * Step 8: Seed Operating Shifts per Factory
     */
    private function seedPulseShifts(int $fibreId, int $pithId, int $growbagsId): void
    {
        $this->command->info('  [8/14] Seeding Factory Shifts...');

        DB::table('pulse_shifts')->truncate();

        $hasStartsNextDay = Schema::hasColumn('pulse_shifts', 'starts_next_day');
        $hasEndsNextDay = Schema::hasColumn('pulse_shifts', 'ends_next_day');

        $rawShifts = [
            // Fibre Factory (Shift 1: 07:00 - 18:00, Shift 2: 19:00 - 06:00 next day)
            [
                'team_id' => $fibreId,
                'name' => 'Shift 1',
                'start_time' => '07:00:00',
                'end_time' => '18:00:00',
                'starts_next_day' => 0,
                'ends_next_day' => 0,
                'is_active' => 1,
            ],
            [
                'team_id' => $fibreId,
                'name' => 'Shift 2',
                'start_time' => '19:00:00',
                'end_time' => '06:00:00',
                'starts_next_day' => 0,
                'ends_next_day' => 1,
                'is_active' => 1,
            ],
            // Pith Factory (Shift 1: 08:00 - 18:00, Shift 2: 18:00 - 08:00 next day)
            [
                'team_id' => $pithId,
                'name' => 'Shift 1',
                'start_time' => '08:00:00',
                'end_time' => '18:00:00',
                'starts_next_day' => 0,
                'ends_next_day' => 0,
                'is_active' => 1,
            ],
            [
                'team_id' => $pithId,
                'name' => 'Shift 2',
                'start_time' => '18:00:00',
                'end_time' => '08:00:00',
                'starts_next_day' => 0,
                'ends_next_day' => 1,
                'is_active' => 1,
            ],
            // GrowBags Factory (Shift 1: 07:00 - 19:00, Shift 2: 20:00 - 06:00)
            [
                'team_id' => $growbagsId,
                'name' => 'Shift 1',
                'start_time' => '07:00:00',
                'end_time' => '19:00:00',
                'starts_next_day' => 0,
                'ends_next_day' => 0,
                'is_active' => 1,
            ],
            [
                'team_id' => $growbagsId,
                'name' => 'Shift 2',
                'start_time' => '20:00:00',
                'end_time' => '06:00:00',
                'starts_next_day' => 0,
                'ends_next_day' => 1,
                'is_active' => 1,
            ],
        ];

        $now = now();
        $shifts = [];
        foreach ($rawShifts as $s) {
            $shift = [
                'team_id' => $s['team_id'],
                'name' => $s['name'],
                'start_time' => $s['start_time'],
                'end_time' => $s['end_time'],
                'is_active' => $s['is_active'],
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if ($hasStartsNextDay) {
                $shift['starts_next_day'] = $s['starts_next_day'];
            }
            if ($hasEndsNextDay) {
                $shift['ends_next_day'] = $s['ends_next_day'];
            }

            $shifts[] = $shift;
        }

        DB::table('pulse_shifts')->insert($shifts);
        $this->command->info('        -> Factory shifts seeded (4 total shifts configured)');
    }

    /**
     * Step 10: Seed Pulse Devices per Factory (Pith, Fibre, GrowBags)
     */
    private function seedPulseDevices(
        int $fibreTeamId,
        int $pithTeamId,
        int $growbagsTeamId,
        int $fibreDeviceId,
        int $pithDeviceId,
        int $growbagsDeviceId
    ): void {
        $this->command->info('  [10/14] Seeding Pulse Devices (Pith, Fibre, GrowBags)...');

        $hasOffTh = Schema::hasColumn('pulse_devices', 'off_th');

        $rawDevices = [
            // Pith Factory Device (watts, duration_device_missing: 100)
            [
                'team_id' => $pithTeamId,
                'device_id' => $pithDeviceId,
                'module' => 'pith',
                'status' => 'active',
                'off_th' => 10,
                'eb_units' => 'watts',
                'idle_th' => 95,
                'efficient_th' => 230,
                'duration_device_msg' => 180,
                'duration_device_missing' => 300,
            ],
            // Fibre Factory Device (kWh, duration_device_missing: 300)
            [
                'team_id' => $fibreTeamId,
                'device_id' => $fibreDeviceId,
                'module' => 'fibre',
                'status' => 'active',
                'off_th' => 10,
                'eb_units' => 'kWh',
                'idle_th' => 95,
                'efficient_th' => 230,
                'duration_device_msg' => 180,
                'duration_device_missing' => 300,
            ],
            // GrowBags Factory Device (kWh, duration_device_missing: 300)
            [
                'team_id' => $growbagsTeamId,
                'device_id' => $growbagsDeviceId,
                'module' => 'growbags',
                'status' => 'active',
                'off_th' => 10,
                'eb_units' => 'kWh',
                'idle_th' => 95,
                'efficient_th' => 230,
                'duration_device_msg' => 180,
                'duration_device_missing' => 300,
            ],
        ];

        $now = now();
        foreach ($rawDevices as $dev) {
            $data = [
                'team_id' => $dev['team_id'],
                'module' => $dev['module'],
                'status' => $dev['status'],
                'eb_units' => $dev['eb_units'],
                'idle_th' => $dev['idle_th'],
                'efficient_th' => $dev['efficient_th'],
                'duration_device_msg' => $dev['duration_device_msg'],
                'duration_device_missing' => $dev['duration_device_missing'],
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if ($hasOffTh) {
                $data['off_th'] = $dev['off_th'];
            }

            DB::table('pulse_devices')->updateOrInsert(
                ['device_id' => $dev['device_id']],
                $data
            );
        }

        $this->command->info('        -> 3 Factory Pulse Devices seeded successfully');
    }

    /**
     * Step 11: Seed Staff Members in staffs table
     */
    private function seedStaffsTable(int $fibreTeamId, int $pithTeamId, int $growbagsTeamId): void
    {
        $this->command->info('  [11/14] Seeding Staffs Table...');

        $staffs = [
            // Fibre Team
            ['team_id' => $fibreTeamId,    'staff_name' => 'Rajesh Kumar',   'salary_per_hr' => 85.00, 'is_active' => 1],
            ['team_id' => $fibreTeamId,    'staff_name' => 'Suresh Babu',    'salary_per_hr' => 90.00, 'is_active' => 1],
            ['team_id' => $fibreTeamId,    'staff_name' => 'Manoj Reddy',    'salary_per_hr' => 80.00, 'is_active' => 1],

            // Pith Team
            ['team_id' => $pithTeamId,     'staff_name' => 'Arun Selvam',    'salary_per_hr' => 85.00, 'is_active' => 1],
            ['team_id' => $pithTeamId,     'staff_name' => 'Ganesan Pillai', 'salary_per_hr' => 90.00, 'is_active' => 1],
            ['team_id' => $pithTeamId,     'staff_name' => 'Murugan Das',    'salary_per_hr' => 80.00, 'is_active' => 1],
            ['team_id' => $pithTeamId,     'staff_name' => 'Vijay Kumar',    'salary_per_hr' => 85.00, 'is_active' => 1],

            // GrowBags Team
            ['team_id' => $growbagsTeamId, 'staff_name' => 'Karthik Raj',    'salary_per_hr' => 95.00, 'is_active' => 1],
            ['team_id' => $growbagsTeamId, 'staff_name' => 'Senthil Nathan', 'salary_per_hr' => 90.00, 'is_active' => 1],
            ['team_id' => $growbagsTeamId, 'staff_name' => 'Pradeep Cocos',  'salary_per_hr' => 85.00, 'is_active' => 1],
        ];

        $now = now();
        foreach ($staffs as $s) {
            DB::table('staffs')->updateOrInsert(
                ['team_id' => $s['team_id'], 'staff_name' => $s['staff_name']],
                array_merge($s, ['created_at' => $now, 'updated_at' => $now])
            );
        }

        $this->command->info('        -> Staffs table seeded ('.count($staffs).' staff members)');
    }

    /**
     * Step 12: Seed Pith Yard Locations
     */
    private function seedPithLocations(int $pithTeamId): void
    {
        $this->command->info('  [12/14] Seeding Pith Locations & Location Groups...');

        $now = now();
        DB::table('pulse_pith_location_groups')->updateOrInsert(
            ['team_id' => $pithTeamId, 'name' => 'Main Processing Yard'],
            ['display_order' => 1, 'is_active' => 1, 'updated_at' => $now]
        );
        $g1Id = DB::table('pulse_pith_location_groups')->where('team_id', $pithTeamId)->where('name', 'Main Processing Yard')->value('id');

        DB::table('pulse_pith_location_groups')->updateOrInsert(
            ['team_id' => $pithTeamId, 'name' => 'Drying Yards'],
            ['display_order' => 2, 'is_active' => 1, 'updated_at' => $now]
        );
        $g2Id = DB::table('pulse_pith_location_groups')->where('team_id', $pithTeamId)->where('name', 'Drying Yards')->value('id');

        DB::table('pulse_pith_location_groups')->updateOrInsert(
            ['team_id' => $pithTeamId, 'name' => 'Storage Sheds'],
            ['display_order' => 3, 'is_active' => 1, 'updated_at' => $now]
        );
        $g3Id = DB::table('pulse_pith_location_groups')->where('team_id', $pithTeamId)->where('name', 'Storage Sheds')->value('id');

        $locations = [
            ['id' => 1, 'team_id' => $pithTeamId, 'group_id' => $g1Id, 'name' => 'Main Yard',          'is_active' => 1, 'low_stock_alert_value' => 500],
            ['id' => 2, 'team_id' => $pithTeamId, 'group_id' => $g1Id, 'name' => 'Secondary Yard',     'is_active' => 1, 'low_stock_alert_value' => 300],
            ['id' => 3, 'team_id' => $pithTeamId, 'group_id' => $g2Id, 'name' => 'Drying Yard A',      'is_active' => 1, 'low_stock_alert_value' => 300],
            ['id' => 4, 'team_id' => $pithTeamId, 'group_id' => $g2Id, 'name' => 'Drying Yard B',      'is_active' => 1, 'low_stock_alert_value' => 300],
            ['id' => 5, 'team_id' => $pithTeamId, 'group_id' => $g3Id, 'name' => 'Storage Shed 1',     'is_active' => 1, 'low_stock_alert_value' => 200],
            ['id' => 6, 'team_id' => $pithTeamId, 'group_id' => $g3Id, 'name' => 'Storage Shed 2',     'is_active' => 1, 'low_stock_alert_value' => 200],
            ['id' => 7, 'team_id' => $pithTeamId, 'group_id' => $g2Id, 'name' => 'Buffer Yard',         'is_active' => 1, 'low_stock_alert_value' => 300],
            ['id' => 8, 'team_id' => $pithTeamId, 'group_id' => $g1Id, 'name' => 'Finished Pith Yard', 'is_active' => 1, 'low_stock_alert_value' => 400],
        ];

        DB::table('pulse_pith_locations')->upsert($locations, ['id'], ['group_id', 'name', 'is_active', 'low_stock_alert_value']);
        $this->command->info('        -> 8 Pith Drying Locations grouped under 3 Location Groups');
    }

    /**
     * Step 13: Seed Master Water Meters and Water Tanks
     */
    private function seedMasterWaterMetersAndTanks(int $pithTeamId, int $pithDeviceId): void
    {
        $this->command->info('  [13/14] Seeding Master Water Meters & Tanks...');

        DB::table('pulse_master_water_meters')->upsert([
            ['device_id' => $pithDeviceId, 'field_id' => 'd27', 'consumption_name' => 'Total water'],
            ['device_id' => $pithDeviceId, 'field_id' => 'd16', 'consumption_name' => 'Husk water'],
        ], ['device_id', 'field_id'], ['consumption_name']);

        DB::table('pulse_master_water_tanks')->upsert([
            ['device_id' => $pithDeviceId, 'field_id' => 'd29', 'tank_name' => 'Underground Tank', 'capacity_litres' => 175000, 'low_alert_percent' => 5.00, 'overflow_alert_percent' => 98.00, 'tank_type' => 'cylindrical', 'cyl_height' => '487.680', 'cyl_diameter' => '679.700'],
            ['device_id' => $pithDeviceId, 'field_id' => 'd30', 'tank_name' => 'Overhead Tank', 'capacity_litres' => 6000, 'low_alert_percent' => 5.00, 'overflow_alert_percent' => 98.00, 'tank_type' => 'cylindrical', 'cyl_height' => '192.020', 'cyl_diameter' => '204.220'],
        ], ['device_id', 'field_id'], ['tank_name', 'capacity_litres', 'low_alert_percent', 'overflow_alert_percent', 'tank_type', 'cyl_height', 'cyl_diameter']);

        $this->command->info('        -> Master Water Meters & Tanks configured');
    }

    /**
     * Step 14: Seed Master GrowBag Machines
     */
    private function seedMasterGrowbagMachines(int $growbagsTeamId, int $growbagsDeviceId): void
    {
        $this->command->info('  [14/14] Seeding Master GrowBag Machines...');

        $machines = [
            ['id' => 1, 'team_id' => $growbagsTeamId, 'device_id' => $growbagsDeviceId, 'field_id' => 'D31', 'machine_name' => 'G1', 'bags_per_punch' => 1, 'is_active' => 1],
            ['id' => 2, 'team_id' => $growbagsTeamId, 'device_id' => $growbagsDeviceId, 'field_id' => 'D32', 'machine_name' => 'G2', 'bags_per_punch' => 2, 'is_active' => 1],
            ['id' => 4, 'team_id' => $growbagsTeamId, 'device_id' => $growbagsDeviceId, 'field_id' => 'D34', 'machine_name' => 'G3', 'bags_per_punch' => 4, 'is_active' => 1],
        ];

        $now = now();
        foreach ($machines as $m) {
            DB::table('pulse_master_growbag_machines')->updateOrInsert(
                ['team_id' => $m['team_id'], 'id' => $m['id']],
                array_merge($m, ['created_at' => $now, 'updated_at' => $now])
            );
        }

        $this->command->info('        -> Master GrowBag Machines configured');
    }
}
