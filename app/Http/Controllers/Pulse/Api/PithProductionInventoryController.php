<?php

namespace App\Http\Controllers\Pulse\Api;

use App\Http\Controllers\Controller;
use App\Models\Pulse\PithInventory;
use App\Models\Pulse\PithLocation;
use App\Models\Pulse\PithProduction;
use App\Models\Pulse\PithWatering;
use App\Models\Settings;
use App\Services\NotificationSenderService;
use App\Services\NumberFormatterService;
use App\Support\Notifications\NotificationType;
use App\Traits\ManagePithInventory;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class PithProductionInventoryController extends Controller
{
    use ManagePithInventory;

    public function getInventory($id)
    {
        $teamId = Auth::user()->activeTeam()->id;

        // Fetch the PithProduction record with shift details for the specified production ID
        $pithProduction = PithProduction::where('id', $id)
            ->where('team_id', $teamId)
            ->with(['shift:id,name'])  // Assuming shift relationship
            ->first();

        if (! $pithProduction) {
            return response()->json(['error' => 'Pith production not found'], 404);
        }

        // Fetch all pith locations for the user's team
        $pithLocations = PithLocation::where('team_id', $teamId)->get();

        // Fetch loads for the specified production ID
        $pithLoads = PithInventory::where('pith_production_id', $id)->get();

        // Initialize location loads array
        $pithLocationLoads = [];
        foreach ($pithLocations as $pithLocation) {
            $pithLocationLoad = $pithLoads->firstWhere('pith_location_id', $pithLocation->id);

            $pithLocationLoads[] = [
                'pith_location_id' => $pithLocation->id,
                'location_name' => $pithLocation->name,
                'cft_offloaded' => $pithLocationLoad->cft_offloaded ?? 0,
                'loads_offloaded' => $pithLocationLoad->cft_offloaded ?? 0,
                'is_archived' => $pithLocationLoad->is_archived ?? 0,
            ];
        }

        $setting = Settings::where('team_id', $teamId)
            ->where('key', 'PRODUCTION_UNIT')
            ->first();

        $defaultProductionUnit = $setting ? $setting->value : 'loads';
        $cftPerLoad = ($setting && $defaultProductionUnit === 'cft')
            ? $setting->value_remark
            : null;

        // Prepare the response with shift details and total loads
        return response()->json([
            //   'production_id' => $id,
            'shift_date' => Carbon::parse($pithProduction->shift_date)->format(config('app.date_format')),
            'shift_name' => $pithProduction->shift->name ?? null,
            'loads_produced' => (float) ($pithProduction->cft_produced ?? 0),
            'total_loads' => $pithProduction->getTotalPithLoadsAttribute(),
            'location_loads' => $pithLocationLoads,
            'units' => [
                'default_production_unit' => $defaultProductionUnit,
                'cft_per_load' => $cftPerLoad,
            ],
        ]);
    }

    public function updateLoads(Request $request)
    {
        // Authenticate the user
        $user = Auth::user();
        if (! $user || ! $user->currentTeam) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Initialize pithLocationLoads from the request
        $productionId = $request->input('production_id');
        $pithLocationLoads = $request->input('location_loads');

        Log::info('inside updateLoads', $pithLocationLoads);

        $pithLocationLoads = array_map(function ($load) {
            $val = $load['cft_offloaded'] ?? $load['loads_offloaded'] ?? 0;
            $load['cft_offloaded'] = $val === '' ? 0 : (float) $val;

            return $load;
        }, $pithLocationLoads);

        // Fetch the production record and its total loads
        $production = PithProduction::select('cft_produced', 'shift_date')
            ->where('id', $productionId)
            ->first();

        if (! $production) {
            return response()->json(['error' => 'Production record not found.'], 404);
        }

        $productionLoadsTotal = $production->cft_produced;

        // Judge the end state, not the payload. This loop only writes the locations the
        // request carries and leaves every other row of the production untouched, so a
        // partial payload summed on its own under-reports the production's real total.
        $keyedLoads = [];

        foreach ($pithLocationLoads as $loadData) {
            $keyedLoads[(int) $loadData['pith_location_id']] = [
                'cft_offloaded' => $loadData['cft_offloaded'] ?? $loadData['loads_offloaded'] ?? 0,
                'is_archived' => $loadData['is_archived'] ?? 0,
            ];
        }

        if ($this->inventoryDistributionError($productionId, $keyedLoads, $productionLoadsTotal) !== null) {
            return response()->json(['error' => 'Total distributed CFT exceeds CFT produced.'], 400);
        }

        // One request is one change to the production's distribution. Written row by row,
        // a failure part way through left the production split across two states.
        DB::transaction(function () use ($pithLocationLoads, $productionId, $production) {
            // Loop through the pithLocationLoads to update the pulse_pith_inventory table
            foreach ($pithLocationLoads as $loadData) {
                $pithLocationId = $loadData['pith_location_id'];  // The location_id should be part of the array

                // If the location is not archived, proceed with saving/updating loads
                if ($loadData['is_archived'] == 0) {
                    $cftOffloaded = $loadData['cft_offloaded'] ?? $loadData['loads_offloaded'] ?? 0;
                    if ($cftOffloaded == 0) {
                        PithInventory::where('pith_location_id', $pithLocationId)
                            ->where('pith_production_id', $productionId)
                            ->delete();
                    } else {
                        PithInventory::updateOrCreate(
                            ['pith_production_id' => $productionId, 'pith_location_id' => $pithLocationId],
                            ['cft_offloaded' => $cftOffloaded, 'date' => $production->shift_date]
                        );
                    }
                }
            }
        });

        return response()->json(['message' => 'Loads updated successfully.'], 200);
    }

    public function closeLocationStock($locationId)
    {
        if (! $locationId) {
            return response()->json(['error' => 'Location ID is required'], 400);
        }

        $user = Auth::user();
        $activeTeam = $user?->activeTeam();
        if (! $user || ! $activeTeam) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Closing a location is a supervisor-tier action. This endpoint carried no
        // role check at all, so any authenticated member of the team — including a
        // reader — could close a location's stock.
        if (! Gate::allows('close-location-stock')) {
            return response()->json(['error' => 'You are not authorized to close a location.'], 403);
        }

        $teamId = $activeTeam->id;

        $location = PithLocation::where('id', $locationId)
            ->where('team_id', $teamId)
            ->first();

        if (! $location) {
            return response()->json(['error' => 'Location not found'], 404);
        }

        // Fetch and archive the location's active loads atomically, so a duplicate
        // request (e.g. a double-click) sees nothing left to close instead of racing
        // the first request over the same rows.
        $closingResult = DB::transaction(function () use ($locationId) {
            $loads = PithInventory::where('pith_location_id', $locationId)
                ->where('is_archived', 0)
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get();

            // Location is already empty (or a prior request already closed it) — nothing to do.
            if ($loads->isEmpty()) {
                return null;
            }

            $firstLoadId = $loads->first()->id;
            $lastLoadId = $loads->last()->id;

            $totalLoadsOffloaded = $loads->sum('cft_offloaded');
            $totalLoadsTaken = $loads->sum('taken_cft_calculated');

            $depletionId = DB::table('pulse_pith_stock_depletions')->insertGetId([
                'pith_location_id' => $locationId,
                'depletion_date' => now(),
                'first_pith_inventory_id' => $firstLoadId,
                'last_pith_inventory_id' => $lastLoadId,
                'total_cft_offloaded' => $totalLoadsOffloaded,
                'total_cft_taken_calculated' => $totalLoadsTaken,
            ]);

            // Archive the current loads, recording the closure that archived them
            PithInventory::where('pith_location_id', $locationId)
                ->where('is_archived', 0)
                ->update(['is_archived' => 1, 'pith_stock_depletion_id' => $depletionId]);

            // Move the batch's waterings onto the depletion — the location starts the next batch dry
            PithWatering::where('pith_location_id', $locationId)
                ->currentBatch()
                ->update(['pith_stock_depletion_id' => $depletionId]);

            return [
                'total_loads_offloaded' => $totalLoadsOffloaded,
                'total_loads_taken' => $totalLoadsTaken,
            ];
        });

        if ($closingResult === null) {
            return response()->json(['message' => 'Location stock is already closed.'], 200);
        }

        // Notify admins with closing details (internal users automatically in BCC)
        try {
            $subject = "Pith Emptied - {$location->name}";
            $bodyLines = [
                'In: '.NumberFormatterService::formatToIndianFormat($closingResult['total_loads_offloaded'], true).' | Out: '.NumberFormatterService::formatToIndianFormat($closingResult['total_loads_taken'], true),
            ];

            app(NotificationSenderService::class)->notifyTeamUsers(
                $teamId,
                [],
                $subject,
                $bodyLines,
                now()->toDateString(),
                now()->toDateString(),
                true, // Send instantly
                NotificationType::PITH_LOCATION_CLOSED
            );
        } catch (\Throwable $e) {
            Log::error('Failed to send location closing notification: '.$e->getMessage());
        }

        return response()->json(['message' => 'Location stock closed successfully.'], 200);
    }
}
