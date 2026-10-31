<?php

namespace App\Actions\Jetstream;

use App\Models\Team;
use Illuminate\Support\Facades\DB;
use Laravel\Jetstream\Contracts\DeletesTeams;

class DeleteTeam implements DeletesTeams
{
    /**
     * Delete the given team with all its related data.
     */
    public function delete(Team $team): void
    {
        DB::beginTransaction();

        try {
            $teamId = $team->id;

            // Delete all related records in order (respecting foreign key constraints)

            // 1. Device-related data

            // Get device IDs for this team first
            $deviceIds = DB::table('pulse_devices')->where('team_id', $teamId)->pluck('device_id')->toArray();

            if (! empty($deviceIds)) {
                DB::table('pulse_device_data')->whereIn('device_id', $deviceIds)->delete();
                DB::table('pulse_device_data_daily_summary')->whereIn('device_id', $deviceIds)->delete();
                DB::table('pulse_device_data_fibre_bales')->whereIn('device_id', $deviceIds)->delete();
                DB::table('pulse_device_data_fibre_mega_bales_weight')->whereIn('device_id', $deviceIds)->delete();
                DB::table('pulse_device_data_growbags')->whereIn('device_id', $deviceIds)->delete();
                DB::table('pulse_device_data_pith_blocks')->whereIn('device_id', $deviceIds)->delete();
                DB::table('pulse_device_data_cft_calc')->whereIn('device_id', $deviceIds)->delete();
                DB::table('pulse_device_data_water_meters')->whereIn('device_id', $deviceIds)->delete();
                DB::table('pulse_device_data_water_tank_levels')->whereIn('device_id', $deviceIds)->delete();
            }

            // 2. Summary tables
            DB::table('pulse_summary_mini_bales')->where('team_id', $teamId)->delete();
            DB::table('pulse_summary_fibre_mega_bales')->where('team_id', $teamId)->delete();
            DB::table('pulse_summary_pith_productions')->where('team_id', $teamId)->delete();
            DB::table('pulse_summary_pith_blocks')->where('team_id', $teamId)->delete();
            DB::table('pulse_summary_growbags')->where('team_id', $teamId)->delete();
            DB::table('pulse_summary_power_states')->where('team_id', $teamId)->delete();
            DB::table('pulse_summary_water_meter')->where('team_id', $teamId)->delete();
            DB::table('pulse_pith_inventory_summary')->where('team_id', $teamId)->delete();

            // 3. Weather data
            DB::table('pulse_weather_data_daily')->where('team_id', $teamId)->delete();
            DB::table('pulse_weather_data_hourly')->where('team_id', $teamId)->delete();

            // 4. Production and inventory
            DB::table('pulse_husks_daily_stock')->where('team_id', $teamId)->delete();

            // Get pith location IDs for this team to delete related records
            $pithLocationIds = DB::table('pulse_pith_locations')->where('team_id', $teamId)->pluck('id')->toArray();

            if (! empty($pithLocationIds)) {
                DB::table('pulse_pith_inventory')->whereIn('pith_location_id', $pithLocationIds)->delete();
                DB::table('pulse_pith_transfers')->whereIn('from_pith_location_id', $pithLocationIds)->delete();
                DB::table('pulse_pith_waterings')->whereIn('pith_location_id', $pithLocationIds)->delete();
                DB::table('pulse_pith_stock_depletions')->whereIn('pith_location_id', $pithLocationIds)->delete();
            }

            // 5. Master data
            DB::table('pulse_pith_locations')->where('team_id', $teamId)->delete();
            DB::table('pulse_pith_location_groups')->where('team_id', $teamId)->delete();
            DB::table('pulse_master_growbag_machines')->where('team_id', $teamId)->delete();
            DB::table('pulse_master_minibale_machines')->where('team_id', $teamId)->delete();

            // Delete master water meters and tanks by device_id
            if (! empty($deviceIds)) {
                DB::table('pulse_master_water_meters')->whereIn('device_id', $deviceIds)->delete();
                DB::table('pulse_master_water_tanks')->whereIn('device_id', $deviceIds)->delete();
            }

            // 6. Assets
            // Get asset IDs for this team to delete related records
            $assetIds = DB::table('pulse_assets')->where('team_id', $teamId)->pluck('id')->toArray();

            if (! empty($assetIds)) {
                // Get outward IDs to delete returns
                $outwardIds = DB::table('pulse_asset_outwards')->whereIn('asset_id', $assetIds)->pluck('outward_id')->toArray();

                if (! empty($outwardIds)) {
                    DB::table('pulse_asset_returns')->whereIn('outward_id', $outwardIds)->delete();
                }

                DB::table('pulse_asset_outwards')->whereIn('asset_id', $assetIds)->delete();
                DB::table('pulse_asset_purchases')->whereIn('asset_id', $assetIds)->delete();
            }

            DB::table('pulse_assets')->where('team_id', $teamId)->delete();
            DB::table('pulse_asset_categories')->where('team_id', $teamId)->delete();

            // 7. Attendance
            DB::table('pulse_attendance')->where('team_id', $teamId)->delete();

            // 8. Staff
            DB::table('staffs')->where('team_id', $teamId)->delete();

            // 9. Settings and shifts
            DB::table('pulse_settings')->where('team_id', $teamId)->delete();
            DB::table('pulse_shifts')->where('team_id', $teamId)->delete();

            // 10. Devices
            DB::table('pulse_devices')->where('team_id', $teamId)->delete();

            // 11. Notifications
            DB::table('notifications')->where('team_id', $teamId)->delete();
            DB::table('pulse_user_notification_preferences')->where('team_id', $teamId)->delete();

            // 12. Team-user relationships
            DB::table('team_user')->where('team_id', $teamId)->delete();

            // 13. Finally, delete the team itself using purge
            $team->purge();

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
