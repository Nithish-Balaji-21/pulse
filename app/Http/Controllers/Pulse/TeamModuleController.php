<?php

namespace App\Http\Controllers\Pulse;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TeamModuleController extends Controller
{
    public function getStatus(Request $request)
    {
        $team = Auth::user()->currentTeam;

        if (! $team) {
            return response()->json(['error' => 'No active team assigned.'], 404);
        }

        return response()->json([
            'pith_module' => (bool) $team->has_pith_module,
            'pith_bales' => (bool) $team->has_pith_module_bales,
            'pith_blocks' => (bool) $team->has_pith_module_blocks,
            'fibre_module' => (bool) $team->has_fibre_module,
            'fibre_weight' => (bool) $team->has_fibre_module_weight,
            'water_meter' => (bool) $team->has_water_meter_module,
            'growbags' => (bool) $team->has_growbags_module,
            'attendance' => (bool) $team->has_attendance_module,
            'geofencing' => (bool) $team->has_geofencing_module,
            'geofencing_max_boundaries' => (int) $team->geofencing_max_boundaries,
        ]);
    }
}
