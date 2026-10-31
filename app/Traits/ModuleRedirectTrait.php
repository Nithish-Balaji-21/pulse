<?php

namespace App\Traits;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

trait ModuleRedirectTrait
{
    /**
     * Check if the team has the specified feature flag.
     * If not, redirect to the first available fallback module route.
     *
     * @param  string  $requiredFeatureFlag  The specific flag required for the current page (e.g. 'has_pith_module')
     */
    protected function handleMissingModules(string $requiredFeatureFlag): ?RedirectResponse
    {
        $team = Auth::user()->currentTeam;

        // If team is null or not set, redirect to dashboard
        if (! $team) {
            return redirect('/dashboard');
        }

        // If the team HAS the required feature flag, allow access
        if ((bool) data_get($team, $requiredFeatureFlag)) {
            return null;
        }

        // --- Fallback Routing Logic ---
        // If the current module is not accessible, find the first available main module

        if ($team->has_pith_module) {
            return redirect("/{$team->id}/iot-pith-loads");
        }

        if ($team->has_fibre_module) {
            return redirect("/{$team->id}/iot-fibre-bales");
        }

        if ($team->has_growbags_module) {
            return redirect("/{$team->id}/iot-growbags");
        }

        // Add water meter fallback if it exists as a main module view
        if ($team->has_water_meter_module ?? false) {
            return redirect("/{$team->id}/iot-water-consume");
        }

        // If absolutely no IoT modules are enabled, abort with 403
        abort(403, 'No available IoT insights module is enabled for this team.');
    }
}
