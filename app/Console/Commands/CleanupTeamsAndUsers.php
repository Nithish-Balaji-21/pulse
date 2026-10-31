<?php

namespace App\Console\Commands;

use App\Models\Team;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class CleanupTeamsAndUsers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cleanup:teams-and-users {--force : Force the operation without confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete all teams except demo factories and clean up users';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if (! $this->option('force')) {
            if (! $this->confirm('This will delete all teams except demo factories and clean up users. Continue?')) {
                $this->info('Operation cancelled.');

                return 0;
            }
        }

        $this->info('Starting cleanup process...');

        // Demo team IDs from config
        $demoTeamIds = [
            config('demo.fibre_team_id', 14),
            config('demo.pith_team_id', 15),
            config('demo.growbags_team_id', 16),
        ];

        $this->info('Demo team IDs to preserve: '.implode(', ', $demoTeamIds));

        // Get all teams except demo teams
        $teamsToDelete = Team::whereNotIn('id', $demoTeamIds)->get();

        $this->info('Found '.$teamsToDelete->count().' teams to delete.');

        // Delete each team with cascading
        foreach ($teamsToDelete as $team) {
            $this->info("Deleting team: {$team->name} (ID: {$team->id})");
            $this->deleteTeamWithCascade($team->id);
        }

        $this->info('Teams deleted successfully.');

        // Now handle users
        $this->info('Cleaning up users...');

        // Keep superadmin
        $superadminEmail = 'imikjagadeesan@gmail.com';

        // Define demo factory admins
        $demoAdmins = [
            ['email' => 'pithadmin@demo.com', 'name' => 'Pith Admin', 'team_id' => config('demo.pith_team_id', 15)],
            ['email' => 'fibreadmin@demo.com', 'name' => 'Fibre Admin', 'team_id' => config('demo.fibre_team_id', 14)],
            ['email' => 'growbagadmin@demo.com', 'name' => 'Growbag Admin', 'team_id' => config('demo.growbags_team_id', 16)],
        ];

        // Get all users except superadmin
        $allUsers = User::where('email', '!=', $superadminEmail)->get();

        foreach ($allUsers as $user) {
            // Check if this user should be kept as a demo admin
            $isDemoAdmin = false;
            foreach ($demoAdmins as $admin) {
                if ($user->email === $admin['email']) {
                    $isDemoAdmin = true;
                    break;
                }
            }

            if (! $isDemoAdmin) {
                $this->info("Deleting user: {$user->email}");
                $user->delete();
            }
        }

        // Update superadmin
        $this->info('Updating superadmin...');
        $superadmin = User::where('email', $superadminEmail)->first();
        if ($superadmin) {
            $superadmin->update([
                'password' => Hash::make('Sandbox@123'),
                'is_superadmin' => 1,
                'is_active' => 1,
            ]);
            $this->info('Superadmin updated.');
        } else {
            $this->warn('Superadmin not found!');
        }

        // Create or update demo admins
        foreach ($demoAdmins as $adminData) {
            $this->info("Processing demo admin: {$adminData['email']}");

            $user = User::updateOrCreate(
                ['email' => $adminData['email']],
                [
                    'name' => $adminData['name'],
                    'password' => Hash::make('democoiiot'),
                    'is_superadmin' => 0,
                    'is_active' => 1,
                    'current_team_id' => $adminData['team_id'],
                    'email_verified_at' => now(),
                ]
            );

            // Ensure user is attached to their team with admin role
            DB::table('team_user')->updateOrInsert(
                [
                    'team_id' => $adminData['team_id'],
                    'user_id' => $user->id,
                ],
                [
                    'role' => 'admin',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            $this->info("Demo admin {$adminData['email']} configured for team {$adminData['team_id']}");
        }

        $this->info('Cleanup completed successfully!');

        return 0;
    }

    /**
     * Delete a team and all its related data
     */
    private function deleteTeamWithCascade($teamId)
    {
        DB::beginTransaction();

        try {
            // Delete all related records in order (respecting foreign key constraints)

            // 1. Device-related data
            $this->info("  - Deleting device data for team {$teamId}");

            // Get device IDs for this team first
            $deviceIds = DB::table('pulse_devices')->where('team_id', $teamId)->pluck('device_id')->toArray();

            if (! empty($deviceIds)) {
                DB::table('pulse_device_data')->whereIn('device_id', $deviceIds)->delete();
                DB::table('pulse_device_data_daily_summary')->whereIn('device_id', $deviceIds)->delete();
                DB::table('pulse_device_data_fibre_bales')->whereIn('device_id', $deviceIds)->delete();
                DB::table('pulse_device_data_fibre_mega_bales_weight')->whereIn('device_id', $deviceIds)->delete();
                DB::table('pulse_device_data_growbags')->whereIn('device_id', $deviceIds)->delete();
                DB::table('pulse_device_data_pith_blocks')->whereIn('device_id', $deviceIds)->delete();
                DB::table('pulse_master_water_tanks')->whereIn('device_id', $deviceIds)->delete();
            }

            // Also delete by team_id where applicable
            DB::table('pulse_device_data_fibre_bales')->where('team_id', $teamId)->delete();
            DB::table('pulse_device_data_fibre_mega_bales_weight')->where('team_id', $teamId)->delete();
            DB::table('pulse_device_data_growbags')->where('team_id', $teamId)->delete();
            DB::table('pulse_device_data_pith_blocks')->where('team_id', $teamId)->delete();

            // 2. Summary tables
            $this->info("  - Deleting summary data for team {$teamId}");
            DB::table('pulse_summary_mini_bales')->where('team_id', $teamId)->delete();
            DB::table('pulse_summary_fibre_mega_bales')->where('team_id', $teamId)->delete();
            DB::table('pulse_summary_pith_productions')->where('team_id', $teamId)->delete();
            DB::table('pulse_summary_pith_blocks')->where('team_id', $teamId)->delete();
            DB::table('pulse_summary_growbags')->where('team_id', $teamId)->delete();
            DB::table('pulse_summary_power_states')->where('team_id', $teamId)->delete();
            DB::table('pulse_summary_water_meter')->where('team_id', $teamId)->delete();

            // 3. Production and inventory
            $this->info("  - Deleting production and inventory data for team {$teamId}");
            DB::table('pulse_husks_daily_stock')->where('team_id', $teamId)->delete();
            DB::table('pulse_pith_inventory')->where('team_id', $teamId)->delete();
            DB::table('pulse_pith_stock_depletions')->where('team_id', $teamId)->delete();

            // 4. Master data
            $this->info("  - Deleting master data for team {$teamId}");
            DB::table('pulse_pith_locations')->where('team_id', $teamId)->delete();
            DB::table('pulse_master_growbag_machines')->where('team_id', $teamId)->delete();
            DB::table('pulse_master_water_meters')->where('team_id', $teamId)->delete();

            // 5. Assets
            $this->info("  - Deleting assets for team {$teamId}");
            DB::table('pulse_asset_returns')->where('team_id', $teamId)->delete();
            DB::table('pulse_asset_outwards')->where('team_id', $teamId)->delete();
            DB::table('pulse_asset_purchases')->where('team_id', $teamId)->delete();
            DB::table('pulse_assets')->where('team_id', $teamId)->delete();
            DB::table('pulse_asset_categories')->where('team_id', $teamId)->delete();

            // 6. Attendance
            $this->info("  - Deleting attendance for team {$teamId}");
            DB::table('pulse_attendance')->where('team_id', $teamId)->delete();

            // 7. Staff
            $this->info("  - Deleting staff for team {$teamId}");
            DB::table('staffs')->where('team_id', $teamId)->delete();

            // 8. Settings and shifts
            $this->info("  - Deleting settings and shifts for team {$teamId}");
            DB::table('pulse_settings')->where('team_id', $teamId)->delete();
            DB::table('pulse_shifts')->where('team_id', $teamId)->delete();

            // 9. Devices
            $this->info("  - Deleting devices for team {$teamId}");
            DB::table('pulse_devices')->where('team_id', $teamId)->delete();

            // 10. Notifications
            $this->info("  - Deleting notifications for team {$teamId}");
            DB::table('notifications')->where('team_id', $teamId)->delete();

            // 11. Activities
            $this->info("  - Deleting activities for team {$teamId}");
            DB::table('activities')->where('team_id', $teamId)->delete();

            // 12. Product purchases
            $this->info("  - Deleting product purchases for team {$teamId}");
            DB::table('pulse_product_purchase')->where('team_id', $teamId)->delete();

            // 13. Team-user relationships
            $this->info("  - Removing team-user relationships for team {$teamId}");
            DB::table('team_user')->where('team_id', $teamId)->delete();

            // 14. Finally, delete the team itself
            $this->info("  - Deleting team record {$teamId}");
            DB::table('teams')->where('id', $teamId)->delete();

            DB::commit();
            $this->info("  ✓ Team {$teamId} deleted successfully with all references");

        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("  ✗ Error deleting team {$teamId}: ".$e->getMessage());
            throw $e;
        }
    }
}
