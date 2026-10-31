<?php

namespace App\Services\Pulse\Dashboard;

use App\Services\Pulse\WaterTankLevelCalculator;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class WaterTankLevelService
{
    public function __construct(private WaterTankLevelCalculator $calculator) {}

    public function get($startDate, $endDate = null, $fieldId = null): array
    {
        $currentTeamId = Auth::user()->currentTeam->id;
        $endDate = $endDate ?? $startDate;

        // Get device_id for this team
        $deviceId = DB::table('pulse_devices')
            ->where('team_id', $currentTeamId)
            ->value('device_id');

        if (! $deviceId) {
            return [
                'tank_name' => null,
                'current_level_litres' => 0,
                'tank_capacity_litres' => 0,
            ];
        }

        return $this->getWaterLevel($deviceId, $fieldId, $endDate);
    }

    // Get water level at end date.
    private function getWaterLevel(int $deviceId, string $fieldId, Carbon $endDate): array
    {
        $tank = DB::table('pulse_master_water_tanks')
            ->where('device_id', $deviceId)
            ->where('field_id', $fieldId)
            ->first();

        if (! $tank) {
            return [
                'tank_name' => null,
                'current_level_litres' => 0,
                'tank_capacity_litres' => 0,
            ];
        }

        $availableHeight = DB::table('pulse_device_data_water_tank_levels')
            ->where('device_id', $deviceId)
            ->where('field_id', $fieldId)
            ->whereDate('level_date', $endDate)
            ->orderByDesc('level_datetime')
            ->value('available_height');

        // available_height = 0 is a valid full-tank reading; only skip when missing.
        if ($availableHeight === null) {
            return [
                'tank_name' => $tank->tank_name,
                'current_level_litres' => 0,
                'tank_capacity_litres' => 0,
            ];
        }

        $litres = $this->calculator->litres($tank, (float) $availableHeight);

        return [
            'tank_name' => $tank->tank_name,
            'current_level_litres' => $litres ?? 0,
            'tank_capacity_litres' => $tank->capacity_litres ?? 0,
        ];
    }
}
