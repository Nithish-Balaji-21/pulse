<?php

namespace App\Services\Pulse\Dashboard;

use App\Models\Pulse\Device;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DeviceStateService
{
    public function get($startDate, $endDate)
    {
        $currentTeamId = Auth::user()->currentTeam->id;

        $deviceId = Device::where('team_id', $currentTeamId)
            ->where('module', 'pith')
            ->where('status', 'active')
            ->value('device_id');

        $statesToShow = ['efficient', 'off', 'idle', 'overload', 'shutdown'];
        $deviceStateAvg = DB::table('pulse_summary_power_states')
            ->select('state', DB::raw('SUM(duration) AS total_duration'))
            ->whereBetween('shift_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->whereIn('state', $statesToShow)
            ->where('device_id', $deviceId)
            ->groupBy('state')
            ->pluck('total_duration', 'state')
            ->toArray();

        $deviceStateAvg = array_replace(array_fill_keys($statesToShow, 0), $deviceStateAvg);

        return $deviceStateAvg;
    }
}
