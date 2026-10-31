<?php

namespace App\Http\Controllers\Pulse;

use App\Http\Controllers\Controller;
use App\Models\Pulse\PithLocationGroup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class PithLocationGroupsController extends Controller
{
    public function index($teamId = null)
    {
        $teamId = $teamId ?? (app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id);

        $groups = PithLocationGroup::where('team_id', $teamId)
            ->withCount([
                'pithLocations',
                'pithLocations as active_pith_locations_count' => function ($query) {
                    $query->where('is_active', true);
                },
            ])
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();

        return response()->json($groups, 200);
    }

    public function store(Request $request, $teamId = null)
    {
        $teamId = $teamId ?? (app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id);

        if (! Gate::allows('pith_location_groups.create')) {
            return response()->json(['error' => 'You are not authorized to add pith location groups.'], 403);
        }

        try {
            $validatedData = $request->validate([
                'name' => 'required|string|max:50|unique:pulse_pith_location_groups,name,NULL,id,team_id,'.$teamId,
                'display_order' => 'nullable|integer|min:0',
            ], [
                'name.required' => 'The name field is required.',
                'name.unique' => 'This group already exists.',
            ]);

            PithLocationGroup::create([
                'team_id' => $teamId,
                'name' => $validatedData['name'],
                'display_order' => (int) ($validatedData['display_order'] ?? 0),
            ]);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        }

        return response()->json(['message' => 'Pith location group created successfully'], 201);
    }

    public function update(Request $request, $id, $teamId = null)
    {
        $teamId = $teamId ?? (app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id);

        if (! Gate::allows('pith_location_groups.edit')) {
            return response()->json(['error' => 'You are not authorized to update pith location groups.'], 403);
        }

        $group = PithLocationGroup::where('id', $id)
            ->where('team_id', $teamId)
            ->first();

        if (! $group) {
            return response()->json(['message' => 'Group does not exist'], 404);
        }

        try {
            $validatedData = $request->validate([
                'name' => 'required|string|max:50|unique:pulse_pith_location_groups,name,'.$id.',id,team_id,'.$teamId,
                'display_order' => 'nullable|integer|min:0',
            ], [
                'name.required' => 'The name field is required.',
                'name.unique' => 'This group already exists.',
            ]);

            $group->update([
                'name' => $validatedData['name'],
                'display_order' => (int) ($validatedData['display_order'] ?? 0),
            ]);

            return response()->json(['message' => 'Pith location group updated successfully'], 200);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        }
    }

    public function edit($id, $teamId = null)
    {
        $teamId = $teamId ?? (app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id);

        if (! Gate::allows('pith_location_groups.edit')) {
            return response()->json(['error' => 'You are not authorized to edit pith location groups.'], 403);
        }

        $group = PithLocationGroup::where('id', $id)
            ->where('team_id', $teamId)
            ->first();

        if (! $group) {
            return response()->json(['message' => 'Group does not exist'], 404);
        }

        return response()->json($group, 200);
    }

    public function destroy($id, $teamId = null)
    {
        $teamId = $teamId ?? (app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id);

        if (! Gate::allows('pith_location_groups.delete')) {
            return response()->json(['error' => 'You are not authorized for this action - delete pith location groups.'], 403);
        }

        $group = PithLocationGroup::where('id', $id)
            ->where('team_id', $teamId)
            ->first();

        if (! $group) {
            return response()->json(['message' => 'Group does not exist'], 404);
        }

        if ($group->pithLocations()->exists()) {
            return response()->json(['message' => 'Cannot delete group because locations are still assigned to it'], 400);
        }

        $group->delete();

        return response()->json(['message' => 'Group deleted successfully'], 200);
    }

    public function toggleActive($id, $teamId = null)
    {
        $teamId = $teamId ?? (app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id);

        if (! Gate::allows('pith_location_groups.edit')) {
            return response()->json(['error' => 'You are not authorized to toggle active status.'], 403);
        }

        $group = PithLocationGroup::where('id', $id)
            ->where('team_id', $teamId)
            ->first();

        if (! $group) {
            return response()->json(['message' => 'Group does not exist'], 404);
        }

        // Deactivating a group that still holds active locations would leave those
        // locations grouped under a hidden parent; reactivating is always allowed.
        if ($group->is_active) {
            $activeLocations = $group->pithLocations()->where('is_active', true)->count();

            if ($activeLocations > 0) {
                return response()->json([
                    'message' => 'Cannot deactivate this group while '.$activeLocations.' active location(s) are assigned to it.',
                    'active_locations' => $activeLocations,
                ], 422);
            }
        }

        $group->is_active = ! $group->is_active;
        $group->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Group status updated successfully.',
            'is_active' => $group->is_active,
        ], 200);
    }
}
