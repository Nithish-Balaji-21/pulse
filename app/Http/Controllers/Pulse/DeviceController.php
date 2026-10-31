<?php

namespace App\Http\Controllers\Pulse;

use App\Http\Controllers\Controller;
use App\Models\Pulse\Device;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DeviceController extends Controller
{
    public function index($teamId = null)
    {
        $teamId = $teamId ?? (app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id);
        $devices = Device::where('team_id', $teamId)
            ->select('device_id', 'module', 'status', 'off_th', 'idle_th', 'efficient_th', 'duration_device_msg', 'duration_device_missing', 'eb_units')
            ->get();

        return response()->json($devices, 200);
    }

    public function store(Request $request, $teamId = null)
    {
        $teamId = $teamId ?? (app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id);
        $user = Auth::user();

        // Check if the user is a super admin
        if (! $user->is_superadmin) {
            return response()->json(['error' => 'You are not authorized to add a device.'], 403);
        }

        try {
            $validatedData = $request->validate([
                'device_id' => 'required|unique:pulse_devices,device_id',
            ],
                [
                    'device_id.required' => 'Device ID is required',
                    'device_id.unique' => 'Device ID already exists',
                ]);

            Device::Create([
                'team_id' => $teamId,
                'device_id' => $validatedData['device_id'],
            ]);

        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        }

        return response()->json(['message' => 'Device created successfully'], 201);
    }

    public function update(Request $request, $id, $teamId = null)
    {
        $teamId = $teamId ?? (app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id);

        if (! Gate::allows('view-superadmin-settings')) {
            return response()->json(['error' => 'You are not authorized to update device.'], 403);
        }

        $device = Device::where('device_id', $id)
            ->where('team_id', $teamId)
            ->first();

        if (! $device) {
            return response()->json(['error' => 'Device not found'], 404);
        }

        try {
            $validatedData = $request->validate([
                'device_id' => ['required', Rule::unique('pulse_devices', 'device_id')->ignore($device->device_id, 'device_id')],
                'module' => 'required|string',
                'off_th' => 'required|numeric',
                'idle_th' => 'required|numeric',
                'efficient_th' => 'required|numeric',
                'duration_device_msg' => 'required|numeric',
                'duration_device_missing' => 'required|numeric',
                'eb_units' => 'required',
            ],
                [
                    'device_id.required' => 'Device ID is required',
                    'device_id.unique' => 'Device ID already exists',
                ]);

            $device->update([
                'device_id' => $validatedData['device_id'],
                'module' => $validatedData['module'],
                'off_th' => $validatedData['off_th'],
                'idle_th' => $validatedData['idle_th'],
                'efficient_th' => $validatedData['efficient_th'],
                'duration_device_msg' => $validatedData['duration_device_msg'],
                'duration_device_missing' => $validatedData['duration_device_missing'],
                'eb_units' => $validatedData['eb_units'],
            ]);

            return response()->json(['message' => 'Device updated successfully'], 200);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        }
    }

    public function edit($device_id, $teamId = null)
    {
        $teamId = $teamId ?? (app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id);

        if (! Gate::allows('view-superadmin-settings')) {
            return response()->json(['error' => 'You are not authorized to edit device.'], 403);
        }

        $device = Device::where('device_id', $device_id)
            ->where('team_id', $teamId)
            ->first();

        if (! $device) {
            return response()->json(['message' => 'Unauthorized or resource does not exist'], 404);
        }

        return response()->json([
            'device_id' => $device->device_id,
            'module' => $device->module,
            'status' => $device->status,
            'eb_units' => $device->eb_units,
            'off_th' => $device->off_th,
            'idle_th' => $device->idle_th,
            'efficient_th' => $device->efficient_th,
            'duration_device_msg' => $device->duration_device_msg,
            'duration_device_missing' => $device->duration_device_missing,
        ], 200);
    }

    public function destroy($id, $teamId = null)
    {
        $teamId = $teamId ?? (app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id);

        if (! Gate::allows('view-superadmin-settings')) {
            return response()->json(['error' => 'You are not authorized for this action - delete device.'], 403);
        }

        $device = Device::where('device_id', $id)
            ->where('team_id', $teamId)
            ->first();

        if (! $device) {
            return response()->json(['message' => 'Unauthorized or resource does not exist'], 404);
        }
        $device->delete();

        return response()->json(['message' => 'Device deleted successfully'], 200);
    }

    public function toggleActivity($id)
    {
        if (! Gate::allows('view-superadmin-settings')) {
            return response()->json(['error' => 'You are not authorized to toggle device status.'], 403);
        }

        $device = Device::where('device_id', $id)->firstOrFail();

        Log::info('Current device status: '.$device->status);
        $device->status = ($device->status === 'active') ? 'inactive' : 'active';
        $device->save();

        Log::info('Updated device status: '.$device->status);

        return response()->json([
            'status' => 'success',
            'message' => 'Device status updated successfully.',
            'new_status' => $device->status,
        ]);
    }
}
