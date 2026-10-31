<?php

namespace App\Http\Controllers\Pulse\Api\Pith;

use App\Http\Controllers\Controller;
use App\Models\Pulse\PithBlocksProduction;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class PithBlocksProductionListController extends Controller
{
    public function index(Request $request)
    {
        $currentTeamId = Auth::user()->currentTeam->id;
        $searchQuery = $request->input('search');
        $date = $request->input('date');

        $query = PithBlocksProduction::where('team_id', $currentTeamId)
            ->with(['staff:id,staff_name'])
            ->orderBy('shift_date', 'desc');

        if ($date) {
            $query->where('shift_date', $date);
        }

        if ($searchQuery) {
            $query->where(function ($q) use ($searchQuery) {
                $q->whereHas('staff', fn ($sq) => $sq->where('staff_name', 'LIKE', "%$searchQuery%"))
                    ->orWhereRaw("DATE_FORMAT(shift_date, '%d.%m.%Y') LIKE ?", ["%$searchQuery%"]);
            });
        }

        $productions = $query->paginate(10);
        $dateFormat = config('app.date_format');
        $timeFormat = config('app.time_format');

        $formattedProductions = $productions->map(function ($production) use ($dateFormat, $timeFormat) {
            return [
                'id' => $production->id,
                'date' => Carbon::parse($production->shift_date)->format($dateFormat),
                'staff' => [
                    'id' => optional($production->staff)->id ?? '',
                    'staff_name' => optional($production->staff)->staff_name ?? '',
                ],
                'shift' => [
                    'id' => null,
                    'start_time' => $production->start_time ? Carbon::parse($production->start_time)->format($timeFormat) : null,
                    'end_time' => $production->end_time ? Carbon::parse($production->end_time)->format($timeFormat) : null,
                ],
                'blocks_expected' => (int) ($production->blocks_expected ?? 0),
                'blocks_produced' => (int) ($production->blocks_produced ?? 0),
                'blocks_in_stock' => (int) ($production->blocks_in_stock ?? 0),
                'sold' => $production->sold,
                'correction' => (int) ($production->correction ?? 0),
            ];
        });

        $productions->setCollection($formattedProductions);

        return response()->json([
            'productions' => $productions,
            'search_query' => $searchQuery,
            'date' => $date,
        ]);
    }

    public function destroy($id)
    {
        $currentTeamId = Auth::user()->currentTeam->id;
        if (! $currentTeamId) {
            return response()->json(['error' => 'No current team found'], 400);
        }

        if (! Gate::allows('pith_blocks.delete')) {
            return response()->json(['error' => 'You are not authorized for this action - delete pith blocks production.'], 403);
        }

        $production = PithBlocksProduction::where('id', $id)->where('team_id', $currentTeamId)->first();
        if (! $production) {
            return response()->json(['error' => 'Pith blocks production ID does not exist or not authorized.'], 400);
        }

        if (! $production->canDeleteRecord()) {
            return response()->json(['error' => 'This pith blocks record is locked or outside the allowed delete window.'], 403);
        }

        $production->delete();

        return response()->json(['message' => 'Pith blocks production deleted successfully.'], 200);
    }
}
