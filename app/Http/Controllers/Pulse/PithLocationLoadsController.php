<?php

namespace App\Http\Controllers\Pulse;

use App\Http\Controllers\Controller;
use App\Models\Pulse\PithInventory;
use App\Models\Pulse\PithLocation;
use App\Models\Pulse\PithWatering;
use App\Models\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class PithLocationLoadsController extends Controller
{
    public function index($locationId)
    {
        $location = $this->findLocationForTeam($locationId);

        if (! $location) {
            return response()->json(['message' => 'Unauthorized or resource does not exist'], 404);
        }

        $pithLocationLoads = [];

        $summary = PithInventory::where('pith_location_id', $locationId)
            ->where('is_archived', 0)
            ->leftJoin('pulse_pith_locations', 'pulse_pith_inventory.pith_location_id', '=', 'pulse_pith_locations.id')
            ->selectRaw('
                        SUM(cft_offloaded) as total_loads_offloaded,
                        SUM(taken_cft_calculated) as taken_loads_calculated,
                        pulse_pith_locations.name as location_name
                    ')
            ->first();

        $pithLocationLoads['totalLoadsOffloaded'] = $summary?->total_loads_offloaded ?? 0;
        $pithLocationLoads['totalLoadsTaken'] = $summary?->taken_loads_calculated ?? 0;
        $pithLocationLoads['remainingStock'] = max(0, round(($summary?->total_loads_offloaded ?? 0) - ($summary?->taken_loads_calculated ?? 0), 1));
        $pithLocationLoads['locationName'] = $summary?->location_name ?? $location->name;

        // A transfer's row carries `transfer` (the other location and the comment) so the app
        // can label it and route its delete to the transfer rather than the plain entry.
        $pithLocationLoads['pithLoads'] = PithInventory::where('pith_location_id', $locationId)
            ->where('is_archived', 0)
            ->orderBy('pulse_pith_inventory.id', 'desc')
            ->with(['transfer.fromLocation', 'transfer.toLocation'])
            ->get(['pulse_pith_inventory.*'])
            ->map(function (PithInventory $load) {
                $transfer = $load->transfer;
                $row = $load->withoutRelations()->toArray();
                $row['transfer'] = $transfer ? [
                    'id' => $transfer->id,
                    'direction' => (float) $load->taken_cft_calculated > 0 ? 'out' : 'in',
                    'from_location_id' => $transfer->from_pith_location_id,
                    'from_location_name' => $transfer->fromLocation?->name,
                    'to_location_id' => $transfer->to_pith_location_id,
                    'to_location_name' => $transfer->toLocation?->name,
                    'note' => $transfer->note,
                ] : null;

                return $row;
            })
            ->values();

        $waterings = $location->waterings()
            ->currentBatch()
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get();

        $pithLocationLoads['totalWaterLitres'] = (int) $waterings->sum('litres');
        $pithLocationLoads['waterCycles'] = $waterings->count();
        $pithLocationLoads['waterings'] = $waterings->map->toApiArray()->values();

        return response()->json($pithLocationLoads, 200);
    }

    public function add()
    {
        if (! Gate::allows('pith_inventory_outward.create')) {
            return response()->json(['error' => 'You are not authorized to add pith load.'], 403);
        }

        $currentTeamId = Auth::user()->currentTeam->id;

        return response()->json([
            'date' => now()->format('Y-m-d'),
        ], 200);
    }

    public function store(Request $request)
    {
        if (! Gate::allows('pith_inventory_outward.create')) {
            return response()->json(['error' => 'You are not authorized to add pith load.'], 403);
        }

        try {
            $validatedData = $request->validate([
                'date' => 'required|date',
                'locationId' => 'required|numeric',
                'takenCft' => 'required|numeric',
            ],
                [
                    'date.required' => 'Date is required.',
                    'locationId.required' => 'Location is required.',
                    'takenCft.required' => 'Taken CFT is required.',
                    'takenCft.numeric' => 'Taken CFT must be a number.',
                ]);

            PithInventory::Create([
                'date' => $validatedData['date'],
                'pith_location_id' => $validatedData['locationId'],
                'taken_cft_calculated' => round($validatedData['takenCft'], 2),
            ]);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        }

        return response()->json(['message' => 'Pith taken marked successfully'], 201);
    }

    public function update(Request $request, $id)
    {
        if (! Gate::allows('pith_inventory_outward.edit')) {
            return response()->json(['error' => 'You are not authorized to update pith load.'], 403);
        }

        $currentTeamId = Auth::user()->currentTeam->id;
        $pithLocationLoad = PithInventory::where('id', $id)
            ->whereHas('pithLocation', function ($query) use ($currentTeamId) {
                $query->where('team_id', $currentTeamId);
            })
            ->first();

        if (! $pithLocationLoad) {
            return response()->json(['message' => 'Unauthorized or resource does not exist'], 404);
        }

        if ($pithLocationLoad->pith_transfer_id !== null) {
            return $this->transferLockedResponse();
        }

        try {
            $validatedData = $request->validate([
                'date' => 'required|date',
                'takenCft' => 'required|numeric',
            ],
                [
                    'date.required' => 'Date is required.',
                    'takenCft.required' => 'Taken CFT is required.',
                    'takenCft.numeric' => 'Taken CFT must be a number.',
                ]);

            $pithLocationLoad->update([
                'date' => $validatedData['date'],
                'taken_cft_calculated' => round($validatedData['takenCft'], 2),
            ]);

            return response()->json(['message' => 'Location load updated successfully'], 200);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }
    }

    public function edit($id)
    {
        if (! Gate::allows('pith_inventory_outward.edit')) {
            return response()->json(['error' => 'You are not authorized to edit pith load.'], 403);
        }

        $currentTeamId = Auth::user()->currentTeam->id;
        $pithLocationLoad = PithInventory::where('id', $id)
            ->whereHas('pithLocation', function ($query) use ($currentTeamId) {
                $query->where('team_id', $currentTeamId);
            })
            ->first();

        if (! $pithLocationLoad) {
            return response()->json(['message' => 'Unauthorized or resource does not exist'], 404);
        }

        if ($pithLocationLoad->pith_transfer_id !== null) {
            return $this->transferLockedResponse();
        }

        return response()->json([
            'location_id' => $pithLocationLoad->pith_location_id,
            'date' => $pithLocationLoad->date,
            'takenCft' => $pithLocationLoad->taken_cft_calculated,
        ], 200);
    }

    public function destroy($id)
    {
        if (! Gate::allows('pith_inventory_outward.delete')) {
            return response()->json(['error' => 'You are not authorized for this action - delete pith location loads.'], 403);
        }

        $currentTeamId = Auth::user()->currentTeam->id;
        $pithLocationLoad = PithInventory::where('id', $id)
            ->whereHas('pithLocation', function ($query) use ($currentTeamId) {
                $query->where('team_id', $currentTeamId);
            })
            ->first();

        if (! $pithLocationLoad) {
            return response()->json(['message' => 'Unauthorized or resource does not exist'], 404);
        }

        if ($pithLocationLoad->pith_transfer_id !== null) {
            return $this->transferLockedResponse();
        }

        $pithLocationLoad->delete();

        return response()->json(['message' => 'Load deleted successfully'], 200);
    }

    public function stockDepletionHistory($locationId)
    {
        if (! $this->findLocationForTeam($locationId)) {
            return response()->json(['message' => 'Unauthorized or resource does not exist'], 404);
        }

        // Fetch historical depletion data for the current location
        $history = DB::table('pulse_pith_stock_depletions')
            ->join('pulse_pith_locations', 'pulse_pith_stock_depletions.pith_location_id', '=', 'pulse_pith_locations.id')
            ->leftJoin('pulse_pith_inventory', function ($join) use ($locationId) {
                $join->on('pulse_pith_inventory.id', '>=', 'pulse_pith_stock_depletions.first_pith_inventory_id')
                    ->on('pulse_pith_inventory.id', '<=', 'pulse_pith_stock_depletions.last_pith_inventory_id')
                    ->where('pulse_pith_inventory.pith_location_id', $locationId);
            })
            ->where('pulse_pith_stock_depletions.pith_location_id', $locationId)
            ->select(
                'pulse_pith_stock_depletions.id as depletion_id',
                'pulse_pith_stock_depletions.depletion_date',
                'pulse_pith_stock_depletions.total_cft_offloaded',
                'pulse_pith_stock_depletions.total_cft_taken_calculated',
                'pulse_pith_inventory.id as load_id',
                'pulse_pith_inventory.date as load_date',
                DB::raw('COALESCE(pulse_pith_inventory.cft_offloaded, 0) as cft_offloaded'), // Handling NULL values
                'pulse_pith_inventory.taken_cft_calculated',
                'pulse_pith_locations.name as location_name'
            )
            ->orderBy('pulse_pith_stock_depletions.depletion_date', 'desc')
            ->orderBy('pulse_pith_stock_depletions.id', 'desc')
            ->get();

        $waterings = PithWatering::whereIn('pith_stock_depletion_id', $history->pluck('depletion_id')->unique())
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get()
            ->groupBy('pith_stock_depletion_id');

        // Group by depletion (not by date — a location closed twice in a day is two batches)
        // and separate load details
        $groupedHistory = $history->groupBy('depletion_id')->values()->map(function ($group, $index) use ($waterings) {
            $batchWaterings = $waterings->get($group->first()->depletion_id) ?? collect();

            return [
                'id' => $index + 1,
                'depletion_date' => $group->first()->depletion_date,
                'total_cft_offloaded' => $group->first()->total_cft_offloaded,
                'total_cft_taken_calculated' => $group->first()->total_cft_taken_calculated,
                'location_name' => $group->first()->location_name,
                'loads' => $group->map(function ($item) {
                    return [
                        'load_id' => $item->load_id,
                        'load_date' => $item->load_date,
                        'cft_offloaded' => $item->cft_offloaded,
                        'taken_cft_calculated' => $item->taken_cft_calculated,
                    ];
                }),
                'total_water_litres' => (int) $batchWaterings->sum('litres'),
                'water_cycles' => $batchWaterings->count(),
                'waterings' => $batchWaterings->map->toApiArray()->values(),
            ];
        });

        return response()->json($groupedHistory->values());
    }

    public function storeSurplus(Request $request)
    {
        if (! Gate::allows('pith_inventory_inward.create')) {
            return response()->json(['error' => 'You are not authorized to add surplus load.'], 403);
        }

        $validatedData = $request->validate([
            'date' => 'required|date',
            'pith_location_id' => 'required|numeric',
            'loads_offloaded' => 'required|numeric',
            'cft_offloaded' => 'nullable|numeric',
            'note' => 'nullable|string|max:255',
        ]);

        $cftOffloaded = $validatedData['cft_offloaded'] ?? $validatedData['loads_offloaded'];

        PithInventory::create([
            'date' => $validatedData['date'],
            'pith_location_id' => $validatedData['pith_location_id'],
            'cft_offloaded' => $cftOffloaded,
            'note' => $validatedData['note'] ?? null,
        ]);

        return response()->json(['message' => 'Surplus loads added successfully'], 201);
    }

    public function editSurplus($id)
    {
        if (! Gate::allows('pith_inventory_inward.edit')) {
            return response()->json(['error' => 'You are not authorized to edit surplus load.'], 403);
        }

        $teamId = Auth::user()->currentTeam->id;

        $pithLocationLoad = PithInventory::findOrFail($id);

        if ($pithLocationLoad->pith_transfer_id !== null) {
            return $this->transferLockedResponse();
        }

        $setting = Settings::where('team_id', $teamId)
            ->where('key', 'PRODUCTION_UNIT')
            ->first();

        $defaultProductionUnit = $setting ? $setting->value : 'loads';

        $cftPerLoad = ($setting && $defaultProductionUnit === 'cft')
            ? $setting->value_remark
            : null;

        return response()->json([
            'date' => $pithLocationLoad->date,
            'cft_offloaded' => $pithLocationLoad->cft_offloaded,
            'loads_offloaded' => $pithLocationLoad->cft_offloaded,
            'note' => $pithLocationLoad->note,
            'units' => [
                'default_production_unit' => $defaultProductionUnit,
                'cft_per_load' => $cftPerLoad,
            ],
        ], 200);
    }

    public function updateSurplus(Request $request, $id)
    {
        if (! Gate::allows('pith_inventory_inward.edit')) {
            return response()->json(['error' => 'You are not authorized to update surplus load.'], 403);
        }

        $validatedData = $request->validate([
            'date' => 'required|date',
            'loads_offloaded' => 'required|numeric',
            'cft_offloaded' => 'nullable|numeric',
            'note' => 'nullable|string|max:255',
        ]);

        $cftOffloaded = $validatedData['cft_offloaded'] ?? $validatedData['loads_offloaded'];

        $pithLocationLoad = PithInventory::findOrFail($id);

        if ($pithLocationLoad->pith_transfer_id !== null) {
            return $this->transferLockedResponse();
        }
        $pithLocationLoad->update([
            'date' => $validatedData['date'],
            'cft_offloaded' => $cftOffloaded,
            'note' => $validatedData['note'] ?? null,
        ]);

        return response()->json(['message' => 'Surplus load updated successfully'], 200);
    }

    public function deleteSurplus($id)
    {
        if (! Gate::allows('pith_inventory_inward.delete')) {
            return response()->json(['error' => 'You are not authorized to delete surplus load.'], 403);
        }

        $pithLocationLoad = PithInventory::findOrFail($id);

        if ($pithLocationLoad->pith_transfer_id !== null) {
            return $this->transferLockedResponse();
        }
        $pithLocationLoad->delete();

        return response()->json(['message' => 'Surplus load deleted successfully'], 200);
    }

    /**
     * A transfer's ledger row changes only by deleting the transfer, which removes both halves.
     */
    private function transferLockedResponse()
    {
        return response()->json([
            'message' => 'This entry is part of a stock transfer. Delete the transfer instead.',
        ], 422);
    }

    private function findLocationForTeam($locationId): ?PithLocation
    {
        return PithLocation::where('id', $locationId)
            ->where('team_id', Auth::user()->activeTeam()?->id)
            ->first();
    }
}
