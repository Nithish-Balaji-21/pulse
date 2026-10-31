<?php

namespace App\Http\Controllers;

use App\Models\Settings;
use App\Models\Team;
use App\Services\Pulse\ProductionEntryPolicy;
use App\Support\Pulse\ProductionEntryPolicyKeys;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SettingsController extends Controller
{
    public function index(Request $request, $teamId = null)
    {
        if (! Gate::allows('factory_customizations.edit') && ! Gate::allows('factory_customizations.view')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        // Use passed teamId, fallback to current team
        if ($teamId) {
            $team = Team::find($teamId);
        } else {
            $team = app()->has('current.team') ? app('current.team') : Auth::user()->currentTeam;
        }

        $allowedModules = [];
        if ($team->has_pith_module) {
            $allowedModules[] = 'pith';
        }
        if ($team->has_fibre_module) {
            $allowedModules[] = 'fibre';
        }
        // Growbags owns a RS_PER_EB_UNIT row like the other two. Without it here the row exists
        // but no one can reach it, which is worse than not having it.
        if ($team->has_growbags_module) {
            $allowedModules[] = 'growbags';
        }

        $settings = Settings::where('team_id', $team->id)
            ->whereIn('module', $allowedModules)
            ->with(['user:id,name'])
            ->select('id', 'team_id', 'module', 'key', 'text', 'value', 'options', 'value_remark', 'updated_by', 'updated_at')
            ->get()
            ->map(function ($setting) {
                if (ProductionEntryPolicyKeys::isPolicyKey($setting->key)) {
                    $setting->text = ProductionEntryPolicyKeys::DEFINITIONS[$setting->key]['text'];
                    $setting->options = '';
                    $setting->value_remark = '';
                }

                return $setting;
            });

        return response()->json($settings, 200);
    }

    public function edit($id, $teamId = null)
    {
        if (! Gate::allows('factory_customizations.edit')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Use passed teamId, fallback to current team
        $teamId = $teamId ?? (app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id);

        $setting = Settings::where('id', $id)
            ->where('team_id', $teamId)
            ->select('key', 'text', 'value', 'options', 'value_remark')
            ->first();

        if (! $setting) {
            return response()->json(['message' => 'Setting not found'], 404);
        }

        if (ProductionEntryPolicyKeys::isPolicyKey($setting->key)) {
            $setting->text = ProductionEntryPolicyKeys::DEFINITIONS[$setting->key]['text'];
        }

        return response()->json($setting, 200);
    }

    public function update(Request $request, $id, $teamId = null)
    {
        if (! Gate::allows('factory_customizations.edit')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Use passed teamId, fallback to current team
        $teamId = $teamId ?? (app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id);

        $setting = Settings::where('id', $id)
            ->where('team_id', $teamId)
            ->first();

        if (! $setting) {
            return response()->json(['message' => 'Unauthorized or resource does not exist'], 404);
        }

        try {
            $validatedData = $request->validate([
                'value' => 'required',
                'value_remark' => 'nullable|string',
            ], [
                'value.required' => 'The value field is required.',
            ]);

            $dataToUpdate = [
                'value' => $validatedData['value'],
                'updated_by' => Auth::id(),
            ];

            if (ProductionEntryPolicyKeys::isPolicyKey($setting->key)) {
                $dataToUpdate['value'] = (string) ProductionEntryPolicyKeys::normalizeDay($validatedData['value']);
                $dataToUpdate['value_remark'] = null;
                $dataToUpdate['options'] = ProductionEntryPolicyKeys::dayOptions();
                $dataToUpdate['text'] = ProductionEntryPolicyKeys::DEFINITIONS[$setting->key]['text'];
            } elseif ($request->has('value_remark')) {
                $dataToUpdate['value_remark'] = $validatedData['value_remark'];
            }

            $setting->update($dataToUpdate);

            if (ProductionEntryPolicyKeys::isPolicyKey($setting->key)) {
                ProductionEntryPolicy::forgetCacheForTeam((int) $teamId);
            }

            return response()->json(['message' => 'Setting updated successfully'], 200);

        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }
    }
}
