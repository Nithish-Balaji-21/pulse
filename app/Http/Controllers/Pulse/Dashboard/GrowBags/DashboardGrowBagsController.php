<?php

namespace App\Http\Controllers\Pulse\Dashboard\GrowBags;

use App\Http\Controllers\Pulse\Dashboard\DashboardController;
use App\Models\Pulse\Shift;
use App\Services\Pulse\Dashboard\GrowBagsProductionService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DashboardGrowBagsController extends DashboardController
{
    public function index(Request $request)
    {
        $team = Auth::user()->currentTeam;

        if (! $team->has_growbags_module) {
            if ($team->has_fibre_module) {
                return redirect("/{$team->id}/dashboard/fibre");
            }
            if ($team->has_pith_module) {
                return redirect("/{$team->id}/dashboard/pith");
            }
            Log::error('No module found for the team!');
            echo 'No module found for the team!';
            exit;
        }

        if (! $request->ajax() && ! $request->wantsJson()) {
            $teamId = $team->id;
            $start = $request->query('start', now()->toDateString());
            $end = $request->query('end', $start);
            $type = $request->query('type', 'day');

            $startTime = Shift::anchorForTeam($teamId);

            $shiftStartWithBuffer = Carbon::today()
                ->setTimeFromTimeString($startTime ?? '08:00:00')
                ->addMinutes(30);

            $machines = DB::table('pulse_master_growbag_machines')
                ->where('team_id', $teamId)
                ->where('is_active', 1)
                ->orderBy('field_id')
                ->get(['id', 'field_id', 'machine_name', 'bags_per_punch']);

            return view('pulse::dashboard.growbagmodule.growbags', [
                'teamId' => $teamId,
                'machines' => $machines,
                'shifts' => $this->getShifts($teamId),
                'shiftStartWithBuffer' => $shiftStartWithBuffer,
                'start' => $start,
                'end' => $end,
                'type' => $type,
            ]);
        }

        // AJAX
        $startDate = Carbon::parse($request->query('start'));
        $endDate = $request->query('end');
        $type = $request->query('type', 'month');

        switch ($type) {
            case 'day':
                $endDate = $endDate ? Carbon::parse($endDate) : $startDate->copy();
                break;
            case 'week':
                $endDate = $endDate ? Carbon::parse($endDate) : $startDate->copy()->addDays(6);
                break;
            case 'month':
                $endDate = Carbon::parse($endDate ?? $startDate)->endOfMonth();
                $startDate = $startDate->startOfMonth();
                break;
            case 'year':
                $startDate = $startDate->startOfYear();
                $endDate = $endDate ? Carbon::parse($endDate)->endOfYear() : $startDate->copy()->endOfYear();
                break;
            case 'range':
                $startDate = $startDate->startOfDay();
                $endDate = Carbon::parse($endDate)->endOfDay();
                break;
        }

        // No attendance here: labour is withdrawn from growbag-only factories, so the salary
        // widget the payload used to feed no longer exists on this dashboard.
        $growbagsData = app(GrowBagsProductionService::class)->get($startDate, $endDate, $type);

        return response()->json([
            'success' => true,
            'data' => $growbagsData,
            'start' => $startDate,
            'end' => $endDate,
            'type' => $type,
        ]);
    }

    /**
     * Active shifts as id => name, the order the summary tiles lay their chips out in.
     */
    private function getShifts($teamId)
    {
        return DB::table('pulse_shifts')
            ->select('id', 'name')
            ->where('team_id', $teamId)
            ->where('is_active', 1)
            ->pluck('name', 'id');
    }
}
