<?php

namespace App\Http\Controllers\Pulse\IoT;

use App\Http\Controllers\Controller;
use App\Models\Pulse\Device;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class IotPithDateController extends Controller
{
    public function index($date = null)
    {
        // Fetch resources (unique states). Colours come from config('colors.states')
        // so this calendar, the state donut and the Pith report cards always agree —
        // and so a team's colour overrides apply here too.
        $stateColors = config('colors.states');

        $resources = [
            ['id' => 'shutdown', 'title' => 'Power Shutdown', 'color' => $stateColors['shutdown']],
            ['id' => 'off', 'title' => 'Machine Off', 'color' => $stateColors['off']],
            ['id' => 'idle', 'title' => 'Machine Underloaded', 'color' => $stateColors['idle']],
            ['id' => 'efficient', 'title' => 'Machine Efficient', 'color' => $stateColors['efficient']],
            ['id' => 'overload', 'title' => 'Machine Overloaded', 'color' => $stateColors['overload']],
            ['id' => 'missing', 'title' => 'Data Missing', 'color' => $stateColors['missing']],
        ];

        return view('pulse::iot-insights.pith.daily', compact('resources', 'date'));
    }

    public function fetchDatewiseData(Request $request)
    {
        $date = $request->query('date');

        $currentTeamId = Auth::user()->currentTeam->id;
        $firstActiveDeviceId = Device::where('team_id', $currentTeamId)
            ->where('module', 'pith')
            ->where('status', 'active')
            ->value('device_id');

        // Fetch events for the selected date
        $events = DB::table('pulse_summary_power_states')
            ->where('device_id', $firstActiveDeviceId)
            ->where('date', $date)
            ->get()
            ->map(function ($record) {
                return [
                    'id' => $record->id,
                    'resourceId' => $record->state,
                    'start' => $record->start_time,
                    'end' => $record->end_time,
                    'title' => $record->state.' ('.gmdate('H:i:s', $record->duration).')',
                ];
            });

        return response()->json([
            'events' => $events,
            'date' => $date,
        ]);
    }
}
