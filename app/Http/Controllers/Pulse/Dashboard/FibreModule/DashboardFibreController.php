<?php

namespace App\Http\Controllers\Pulse\Dashboard\FibreModule;

use App\Exports\FibreDashboardExport;
use App\Http\Controllers\Pulse\Dashboard\DashboardController;
use App\Models\Pulse\Shift;
use App\Services\NumberFormatterService;
use App\Services\Pulse\Dashboard\FibreProductionService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

class DashboardFibreController extends DashboardController
{
    protected $deviceId = null;

    protected $deviceYear = null;

    public function index(Request $request)
    {
        $team = Auth::user()->currentTeam;

        // echo $team->id; exit;
        if (! $team->has_fibre_module) {
            if ($team->has_growbags_module) {
                return redirect("/{$team->id}/dashboard/growbags");
            }
            if ($team->has_pith_module) {
                return redirect("/{$team->id}/dashboard/pith");
            }
            Log::error('No module found for the team!');
            echo 'No module found for the team!';
            exit;
        }

        // For non-ajax requests, return the view
        if (! $request->ajax() && ! $request->wantsJson()) {
            $teamId = $team->id;
            $start = $request->query('start', now()->toDateString());
            $end = $request->query('end', $start);
            $type = $request->query('type', 'month');

            $startTime = Shift::anchorForTeam($teamId);

            $shiftStartWithBuffer = Carbon::today()->setTimeFromTimeString($startTime)->addMinutes(30);

            return view('pulse::dashboard.fibremodule.fibre', [
                'shifts' => $this->getShifts($teamId),
                'shiftStartWithBuffer' => $shiftStartWithBuffer,
                'start' => $start,
                'end' => $end,
                'type' => $type,
                'teamId' => Auth::user()->currentTeam->id,
            ]);
        }

        $startDate = Carbon::parse($request->query('start'));
        $endDate = $request->query('end');
        $type = $request->query('type', 'month');

        switch ($type) {
            case 'day':
                $endDate = $endDate ? Carbon::parse($endDate) : $startDate->copy();
                break;

            case 'week':
                $endDate = $endDate ? Carbon::parse($endDate) : $startDate->copy()->addDays(6);
                if ($endDate->equalTo($startDate)) {
                    $endDate = $startDate->copy()->addDays(6);
                }
                break;

            case 'month':
                $endDate = $endDate ? Carbon::parse($endDate) : $startDate->copy()->endOfMonth();
                $startDate = $startDate->copy()->startOfMonth();
                break;

            case 'year':
                $startDate = $startDate->copy()->startOfYear();
                $endDate = $endDate ? Carbon::parse($endDate)->endOfYear() : $startDate->copy()->endOfYear();
                break;

            case 'range':
                $startDate = $startDate->startOfDay();
                $endDate = Carbon::parse($endDate)->endOfDay();
                break;
        }

        $dashboardData = $this->fibreModule($startDate, $endDate, $type);

        return response()->json([
            'success' => true,
            'data' => $dashboardData,
            'start' => $startDate,
            'end' => $endDate,
            'type' => $type,
        ]);
    }

    private function fibreModule($startDate, $endDate, $type)
    {
        return [
            'fibreProduction' => app(FibreProductionService::class)->get($startDate, $endDate, $type),
        ];
    }

    private function getShifts($teamId)
    {
        return DB::table('pulse_shifts')
            ->select('id', 'name')
            ->where('team_id', $teamId)
            ->where('is_active', 1)
            ->pluck('name', 'id');
    }

    public function export(Request $request)
    {
        $start = Carbon::parse($request->query('start', now()->toDateString()));

        $teamId = Auth::user()->currentTeam->id;
        $deviceIds = DB::table('pulse_devices')->where('team_id', $teamId)->pluck('device_id');
        $shiftStartTime = Shift::anchorForTeam($teamId);

        // day export
        $windowStart = $start->copy()->setTimeFromTimeString($shiftStartTime);
        $windowEnd = $windowStart->copy()->addHours(24)->subSecond();
        $dateLabel = 'Date: '.$start->format('d-M-Y');

        $bales = DB::table('pulse_device_data_fibre_bales')
            ->whereIn('device_id', $deviceIds)
            ->whereBetween('bale_datetime', [$windowStart, $windowEnd])
            ->orderBy('bale_datetime', 'asc')
            ->select('bale_datetime')
            ->get();

        $weights = DB::table('pulse_device_data_fibre_mega_bales_weight')
            ->whereIn('device_id', $deviceIds)
            ->whereBetween('bale_weight_datetime', [$windowStart, $windowEnd])
            ->orderBy('bale_weight_datetime', 'asc')
            ->select('bale_weight_datetime', 'bale_weight')
            ->get();

        $totalBales = $bales->count();
        $totalWeights = $weights->count();

        // Total weight — show kgs if under 1000, else convert to tons
        $totalWeightKg = $weights->sum('bale_weight');
        $totalWeightFormatted = $totalWeightKg >= 1000
        ? NumberFormatterService::formatToIndianFormat($totalWeightKg / 1000, true).' tons'
        : NumberFormatterService::formatToIndianFormat($totalWeightKg, true).' kgs';

        $rows = collect([['Count', 'Bale Produced Time', 'Bale Weighed Time', 'Weight (kgs)']]);

        $max = max($bales->count(), $weights->count(), 1);
        for ($i = 0; $i < $max; $i++) {
            $bale = $bales->get($i);
            $weight = $weights->get($i);

            $baleTime = $bale ? Carbon::parse($bale->bale_datetime)->format('d-M-y g:i A') : '';
            $weightTime = '';
            $weightValue = '';

            if ($weight) {
                $weightTime = Carbon::parse($weight->bale_weight_datetime)->format('d-M-y g:i A');
                $weightValue = NumberFormatterService::formatToIndianFormat($weight->bale_weight, true);
            }

            $rows->push([$i + 1, $baleTime, $weightTime, $weightValue]);
        }

        $filename = 'fibre-bales-'.$start->format('Ymd').'.xlsx';

        return Excel::download(
            new FibreDashboardExport($rows, $dateLabel, 'day', $totalBales, $totalWeights, $totalWeightFormatted),
            $filename
        );
    }
}
