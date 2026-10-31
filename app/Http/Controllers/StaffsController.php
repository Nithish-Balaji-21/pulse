<?php

namespace App\Http\Controllers;

use App\Models\Staff;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class StaffsController extends Controller
{
    public function index($teamId = null)
    {
        $teamId = $teamId ?? (app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id);

        $staffs = Staff::where('team_id', $teamId)
            ->select('id', 'team_id', 'staff_name', 'is_active', 'salary_per_hr')
            ->orderBy('staff_name', 'asc')
            ->get();

        return response()->json($staffs, 200);
    }

    public function store(Request $request, $teamId = null)
    {
        if (! Gate::allows('manage_staff.create')) {
            return response()->json(['error' => 'You are not authorized to add pith locations.'], 403);
        }

        $teamId = $teamId ?? (app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id);

        try {
            $validatedData = $request->validate(
                [
                    'staff_name' => 'required|string|Unique:staffs,staff_name,NULL,id,team_id,'.$teamId,
                    'salary_per_hr' => 'required|numeric',
                ],
                [
                    'staff_name.required' => 'The staff name field is required.',
                    'staff_name.unique' => 'The staff name already exists.',
                    'salary_per_hr.required' => 'The salary per hr field is required.',
                    'salary_per_hr.numeric' => 'The salary per hr must be a number.',
                ]);

            Staff::create([
                'staff_name' => trim($validatedData['staff_name']),
                'team_id' => $teamId,
                'salary_per_hr' => $validatedData['salary_per_hr'],
            ]);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 500);
        }

        return response()->json(['message' => 'Staff created successfully.'], 201);
    }

    public function update(Request $request, $id, $teamId = null)
    {
        if (! Gate::allows('manage_staff.edit')) {
            return response()->json(['error' => 'You are not authorized to update pith locations.'], 403);
        }

        $teamId = $teamId ?? (app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id);
        $staff = Staff::where('id', $id)
            ->where('team_id', $teamId)
            ->first();

        if (! $staff) {
            return response()->json(['message' => 'Unauthorized or resource does not exist'], 404);
        }

        try {
            $validatedData = $request->validate([
                'staff_name' => 'required|string|unique:staffs,staff_name,'.$id.',id,team_id,'.$teamId,
                'salary_per_hr' => 'required|numeric',
            ],
                [
                    'staff_name.required' => 'The staff name field is required.',
                    'staff_name.unique' => 'The staff name already exists.',
                    'salary_per_hr.required' => 'The salary per hr field is required.',
                    'salary_per_hr.numeric' => 'The salary per hr must be a number.',
                ]);

            $staff->update([
                'staff_name' => trim($validatedData['staff_name']),
                'salary_per_hr' => $validatedData['salary_per_hr'],
            ]);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 500);
        }

        return response()->json(['message' => 'Staff updated successfully.'], 200);
    }

    public function edit($id, $teamId = null)
    {
        if (! Gate::allows('manage_staff.edit')) {
            return response()->json(['error' => 'You are not authorized to edit pith locations.'], 403);
        }

        $teamId = $teamId ?? (app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id);
        $staff = Staff::where('id', $id)
            ->where('team_id', $teamId)
            ->first();

        if (! $staff) {
            return response()->json(['message' => 'Unauthorized or resource does not exist'], 404);
        }

        return response()->json([
            'id' => $staff->id,
            'staff_name' => $staff->staff_name,
            'salary_per_hr' => $staff->salary_per_hr,
            'is_active' => $staff->is_active,
        ], 200);
    }

    public function destroy($id, $teamId = null)
    {
        if (! Gate::allows('manage_staff.delete')) {
            return response()->json(['error' => 'You are not authorized for this action - delete staffs.'], 403);
        }

        $teamId = $teamId ?? (app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id);
        $staff = Staff::where('id', $id)
            ->where('team_id', $teamId)
            ->first();

        if (! $staff) {
            return response()->json(['message' => 'Unauthorized or resource does not exist'], 404);
        }

        $isReferred = DB::select('
            SELECT 1
            FROM dual
            WHERE EXISTS (SELECT 1 FROM pulse_summary_pith_productions WHERE staff_id = ?)
            OR EXISTS (SELECT 1 FROM pulse_summary_mini_bales WHERE staff_id = ?)
            OR EXISTS (SELECT 1 FROM pulse_attendance WHERE staff_id = ?)
        ', [$id, $id, $id]);

        if (! empty($isReferred)) {
            return response()->json(['error' => 'This staff is referred in production records and cannot be deleted. Instead, you can deactivate.'], 422);
        }

        $staff->delete();

        return response()->json(['message' => 'Staff deleted successfully'], 200);
    }

    public function toggleActivity($id, $teamId = null)
    {
        if (! Gate::allows('manage_staff.edit')) {
            return response()->json(['error' => 'You are not authorized to toggle active status.'], 403);
        }

        $staff = Staff::findOrFail($id);
        $staff->is_active = ! $staff->is_active;
        $staff->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Staff status updated successfully.',
            'is_active' => $staff->is_active,
        ]);
    }
}
