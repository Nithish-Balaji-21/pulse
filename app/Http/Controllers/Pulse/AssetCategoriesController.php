<?php

namespace App\Http\Controllers\Pulse;

use App\Http\Controllers\Controller;
use App\Models\Pulse\AssetCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AssetCategoriesController extends Controller
{
    public function index($teamId = null)
    {
        $teamId = $teamId ?? (app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id);
        $assetCategories = AssetCategory::where('team_id', $teamId)
            ->select('id', 'team_id', 'name', 'is_active')
            ->get();

        return response()->json($assetCategories, 200);
    }

    public function store(Request $request, $teamId = null)
    {
        $teamId = $teamId ?? (app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id);
        if (! Gate::allows('asset_categories.create')) {
            return response()->json(['error' => 'You are not authorized to add asset category.'], 403);
        }

        try {
            $validatedData = $request->validate([
                'name' => 'required|string|unique:pulse_asset_categories,name,NULL,id,team_id,'.$teamId,
            ], [
                'name.required' => 'The name field is required.',
                'name.unique' => 'This category exists already.',
            ]);

            AssetCategory::Create([
                'team_id' => $teamId,
                'name' => $validatedData['name'],
            ]);
        } catch (ValidationException $e) {
            Log::error('Error creating asset category: ', ['error' => $e->getMessage()]);

            return response()->json(['error' => $e->errors()], 422);
        }

        return response()->json(['message' => 'Asset category created successfully'], 201);
    }

    public function update(Request $request, $id, $teamId = null)
    {
        $teamId = $teamId ?? (app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id);
        if (! Gate::allows('asset_categories.edit')) {
            return response()->json(['error' => 'You are not authorized to update asset category.'], 403);
        }

        $assetCategory = AssetCategory::where('id', $id)
            ->where('team_id', $teamId);

        try {
            $validatedData = $request->validate([
                'name' => 'required|string|unique:pulse_asset_categories,name,'.$id.',id,team_id,'.$teamId,
            ], [
                'name.required' => 'The name field is required.',
                'name.unique' => 'This category exists already.',
            ]);

            $assetCategory->update([
                'name' => $validatedData['name'],
            ]);

            return response()->json(['message' => 'Asset category updated successfully'], 200);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        }
    }

    public function edit($id, $teamId = null)
    {
        $teamId = $teamId ?? (app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id);

        if (! Gate::allows('asset_categories.edit')) {
            return response()->json(['error' => 'You are not authorized to edit asset category.'], 403);
        }

        $assetCategory = AssetCategory::where('id', $id)
            ->where('team_id', $teamId)
            ->first();

        if (! $assetCategory) {
            return response()->json(['message' => 'Category does not exist'], 404);
        }

        return response()->json($assetCategory, 200);
    }

    public function destroy($id, $teamId = null)
    {
        $teamId = $teamId ?? (app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id);

        if (! Gate::allows('asset_categories.delete')) {
            return response()->json(['error' => 'You are not authorized for this action - delete asset category.'], 403);
        }

        $assetCategory = AssetCategory::where('id', $id)
            ->where('team_id', $teamId)
            ->first();

        if (! $assetCategory) {
            return response()->json(['message' => 'Category does not exist'], 404);
        }

        $hasAssets = $assetCategory->assets()->exists();
        if ($hasAssets) {
            return response()->json(['message' => 'Cannot delete category because it is referenced in assets'], 400);
        }

        $assetCategory->delete();

        return response()->json(['message' => 'Category deleted successfully'], 200);
    }

    public function toggleActive($id)
    {
        if (! Gate::allows('asset_categories.edit')) {
            return response()->json(['error' => 'You are not authorized to change category status.'], 403);
        }

        $assetCategory = AssetCategory::findOrFail($id);

        $assetCategory->is_active = ! $assetCategory->is_active;
        $assetCategory->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Category status updated successfully.',
            'is_active' => $assetCategory->is_active,
        ]);
    }
}
