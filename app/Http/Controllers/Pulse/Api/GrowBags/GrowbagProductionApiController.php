<?php

namespace App\Http\Controllers\Pulse\Api\GrowBags;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class GrowbagProductionApiController extends Controller
{
    public function index(Request $request)
    {
        $currentTeam = Auth::user()->currentTeam;
        if (! $currentTeam || ! $currentTeam->has_growbags_module) {
            return response()->json(['error' => 'Growbags module is not enabled for this factory.'], 403);
        }

        $teamId = $currentTeam->id;
        $searchQuery = $request->input('search');
        $date = $request->input('date');
        $shiftId = $request->input('shift_id');
        $machineId = $request->input('machine_id');
        $perPage = (int) $request->input('per_page', 25);

        $query = DB::table('pulse_summary_growbags')
            ->where('team_id', $teamId)
            ->when($searchQuery, function ($q) use ($searchQuery) {
                $q->where(function ($sub) use ($searchQuery) {
                    $sub->where('machine_name', 'like', '%'.$searchQuery.'%')
                        ->orWhere('shift_name', 'like', '%'.$searchQuery.'%')
                        ->orWhereRaw("DATE_FORMAT(shift_date, '%d.%m.%Y') LIKE ?", ['%'.$searchQuery.'%'])
                        ->orWhereRaw("DATE_FORMAT(shift_date, '%d/%m/%Y') LIKE ?", ['%'.$searchQuery.'%']);
                });
            })
            ->when($date, function ($q) use ($date) {
                $q->where('shift_date', 'like', $date.'%');
            })
            ->when($shiftId, function ($q) use ($shiftId) {
                $q->where('shift_id', $shiftId);
            })
            ->when($machineId, function ($q) use ($machineId) {
                $q->where('growbag_machine_id', $machineId);
            })
            ->orderBy('shift_date', 'desc')
            ->orderBy('shift_id', 'asc')
            ->orderBy('growbag_machine_id', 'asc');

        $productions = $query->paginate($perPage);

        $dateFormat = config('app.date_format', 'd/m/Y');
        $machinePalette = config('colors.growbags_charts.machines', []);

        $machines = DB::table('pulse_master_growbag_machines')
            ->where('team_id', $teamId)
            ->where('is_active', 1)
            ->orderBy('machine_name')
            ->get()
            ->map(function ($m, $index) use ($machinePalette) {
                return [
                    'id' => $m->id,
                    'machine_name' => $m->machine_name,
                    'color' => $machinePalette[$index] ?? '#6b7280',
                ];
            });

        $shifts = DB::table('pulse_shifts')
            ->where('team_id', $teamId)
            ->where('is_active', 1)
            ->orderBy('name')
            ->get(['id', 'name', 'start_time', 'end_time']);

        $formattedProductions = $productions->getCollection()->map(function ($row) use ($dateFormat, $machines, $shifts) {
            $machine = $machines->firstWhere('id', $row->growbag_machine_id);
            $shift = $shifts->firstWhere('id', $row->shift_id);

            return [
                'id' => $row->id ?? 0,
                'shift_date' => $row->shift_date,
                'date' => Carbon::parse($row->shift_date)->format($dateFormat),
                'shift_id' => $row->shift_id,
                'shift_name' => $row->shift_name ?? ($shift->name ?? ('Shift '.$row->shift_id)),
                'start_time' => $shift->start_time ?? null,
                'end_time' => $shift->end_time ?? null,
                'growbag_machine_id' => $row->growbag_machine_id,
                'machine_name' => $row->machine_name ?? ($machine['machine_name'] ?? 'Machine'),
                'machine_color' => $machine['color'] ?? '#6b7280',
                'bags_produced' => (int) ($row->bags_produced ?? 0),
                'eb_units' => (float) ($row->eb_units ?? 0),
                'eb_price' => (float) ($row->eb_price ?? 0),
            ];
        });

        $productions->setCollection($formattedProductions);

        return response()->json([
            'productions' => $productions,
            'machines' => $machines,
            'shifts' => $shifts,
            'search_query' => $searchQuery,
            'date' => $date,
        ]);
    }
}
