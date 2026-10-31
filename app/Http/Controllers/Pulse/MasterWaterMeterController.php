<?php

namespace App\Http\Controllers\Pulse;

use App\Http\Controllers\Controller;
use App\Models\Pulse\Device;
use App\Models\Pulse\MasterWaterMeter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class MasterWaterMeterController extends Controller
{
    /** List all water meters for the current team */
    public function index($teamId = null)
    {
        $teamId = $teamId ?? (app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id);

        // Get all device IDs belonging to the current team
        $deviceIds = Device::where('team_id', $teamId)->pluck('device_id');

        // Fetch meters linked to these devices
        $meters = MasterWaterMeter::select('id', 'device_id', 'field_id', 'consumption_name')
            ->whereIn('device_id', $deviceIds)
            ->get();

        return response()->json($meters, 200);
    }

    /** Store a new water meter */
    public function store(Request $request, $teamId = null)
    {
        $teamId = $teamId ?? (app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id);

        if (! Auth::user()->isSuperadmin()) {
            return response()->json(['error' => 'Only Superadmin can add a water meter.'], 403);
        }

        if (! Gate::allows('manage-factory-configuration')) {
            return response()->json(['error' => 'You are not authorized to add a water meter.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'device_id' => [
                'required',
                Rule::exists('pulse_devices', 'device_id')->where(fn ($q) => $q->where('team_id', $teamId)),
            ],
            'field_id' => [
                'required',
                Rule::in(['d16', 'd27']),
                function ($attribute, $value, $fail) use ($request) {
                    if ($value && MasterWaterMeter::where('device_id', $request->device_id)
                        ->where('field_id', $value)
                        ->exists()) {
                        $fail('The field id has already been taken.');
                    }
                },
            ],
            'consumption_name' => [
                'required', 'string', 'max:100',
                function ($attribute, $value, $fail) use ($request) {
                    if (MasterWaterMeter::where('device_id', $request->device_id)
                        ->where('consumption_name', $value)
                        ->exists()) {
                        $fail('The consumption name has already been taken for this device.');
                    }
                },
            ],
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        $meter = MasterWaterMeter::create($validator->validated());

        return response()->json($meter, 201);
    }

    /** Get single water meter for edit */
    public function edit($id, $teamId = null)
    {
        $teamId = $teamId ?? (app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id);

        if (! Gate::allows('manage-factory-configuration')) {
            return response()->json(['error' => 'You are not authorized to edit water meter.'], 403);
        }

        $deviceIds = Device::where('team_id', $teamId)->pluck('device_id');

        $meter = MasterWaterMeter::whereIn('device_id', $deviceIds)->find($id);

        if (! $meter) {
            return response()->json(['error' => 'Water meter not found'], 404);
        }

        return response()->json($meter, 200);
    }

    /** Update water meter */
    public function update(Request $request, $id, $teamId = null)
    {
        $teamId = $teamId ?? (app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id);

        if (! Gate::allows('manage-factory-configuration')) {
            return response()->json(['error' => 'You are not authorized to update water meter.'], 403);
        }

        $deviceIds = Device::where('team_id', $teamId)->pluck('device_id');

        $meter = MasterWaterMeter::whereIn('device_id', $deviceIds)->find($id);

        if (! $meter) {
            return response()->json(['error' => 'Water meter not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'device_id' => [
                'required',
                Rule::exists('pulse_devices', 'device_id')->where(fn ($q) => $q->where('team_id', $teamId)),
            ],
            'field_id' => [
                'required',
                function ($attribute, $value, $fail) use ($request, $meter) {
                    if ($value && MasterWaterMeter::where('device_id', $request->device_id)
                        ->where('field_id', $value)
                        ->where('id', '!=', $meter->id)
                        ->exists()) {
                        $fail('The field id has already been taken.');
                    }
                },
            ],
            'consumption_name' => [
                'required', 'string', 'max:100',
                function ($attribute, $value, $fail) use ($request, $meter) {
                    if (MasterWaterMeter::where('device_id', $request->device_id)
                        ->where('consumption_name', $value)
                        ->where('id', '!=', $meter->id)
                        ->exists()) {
                        $fail('The consumption name has already been taken for this device.');
                    }
                },
            ],
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        $meter->update($validator->validated());

        return response()->json($meter, 200);
    }

    /** Delete water meter */
    public function destroy($id, $teamId = null)
    {
        $teamId = $teamId ?? (app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id);

        if (! Auth::user()->isSuperadmin()) {
            return response()->json(['error' => 'Only Superadmin can delete water meter.'], 403);
        }

        if (! Gate::allows('manage-factory-configuration')) {
            return response()->json(['error' => 'You are not authorized to delete water meter.'], 403);
        }

        $deviceIds = Device::where('team_id', $teamId)->pluck('device_id');

        $meter = MasterWaterMeter::whereIn('device_id', $deviceIds)->find($id);

        if (! $meter) {
            return response()->json(['error' => 'Water meter not found'], 404);
        }

        $meter->delete();

        return response()->json(['message' => 'Water meter deleted successfully'], 200);
    }
}
