<?php

namespace App\Http\Controllers\Pulse\IoT;

use App\Http\Controllers\Controller;
use App\Models\Pulse\Device;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class IotFibreApiController extends Controller
{
    public function index(Request $request)
    {
        $team = Auth::user()->currentTeam;
        $currentTeamId = $team->id;
        $deviceId = Device::where('team_id', $currentTeamId)
            ->where('module', 'fibre')
            ->where('status', 'active')
            ->value('device_id');
        if (! $deviceId) {
            return response()->json(['error' => 'No active fibre device found for team: '.$currentTeamId], 404);
        }

        $date = $request->input('start', now()->toDateString());
        $mode = $request->input('mode', 'calendar');

        $liveProduction = $this->fetchLiveProduction($team, $deviceId, $date, $mode);

        return response()->json($liveProduction);
    }

    private function fetchLiveProduction($team, $deviceId, $date, $mode)
    {
        // Resolve timeline window based on mode
        [$startOfTimeline, $endOfTimeline] =
        $this->resolveTimelineWindow($team, $date, $mode);

        [$calendarStart] = $this->resolveTimelineWindow($team, $date, 'calendar');

        [$shiftStart] = $this->resolveTimelineWindow($team, $date, 'shift');

        $fibreData = $this->generateFibreTimeline($deviceId, $startOfTimeline, $endOfTimeline);
        $totalBales = array_sum(array_column($fibreData, 'count'));

        $weightData = [];
        if ($team->has_fibre_module_weight) {
            $weightData = $this->generateWeightTimeline($deviceId, $startOfTimeline, $endOfTimeline);
            $totalWeighed = array_sum(array_column($weightData, 'count'));
        }

        // Check if all two are empty (only contain the default 'off' state)
        $isFibreDataEmpty = $fibreData == [] || count($fibreData) === 1 && $fibreData[0]['state'] === 'off';
        $isWeightDataEmpty = $weightData == [] || count($weightData) === 1 && $weightData[0]['state'] === 'off';

        $hasData = (! $isFibreDataEmpty || ! $isWeightDataEmpty);

        return [
            'fibreData' => $fibreData,
            'weightData' => $weightData,
            'totalBales' => $totalBales ?? 0,
            'totalWeighed' => $totalWeighed ?? 0,
            'calendarStartTime' => $calendarStart->format('H:i'),
            'shiftStartTime' => $shiftStart->format('H:i'),
            'hasData' => $hasData,
        ];
    }

    private function generateFibreTimeline($deviceId, $start, $end)
    {
        $fibreEvents = DB::table('pulse_device_data_fibre_bales')
            ->where('device_id', $deviceId)
            ->whereBetween('bale_datetime', [$start, $end])
            ->where('is_duplicate', 0)
            ->orderBy('bale_datetime', 'asc')
            ->select('bale_datetime as event_datetime', 'bales_counter')
            ->get();

        return $this->generateActivityTimeline($fibreEvents, $start, 15, 'bales_counter');
    }

    private function generateWeightTimeline($deviceId, $start, $end)
    {
        $weightEvents = DB::table('pulse_device_data_fibre_mega_bales_weight')
            ->where('device_id', $deviceId)
            ->whereBetween('bale_weight_datetime', [$start, $end])
            ->orderBy('bale_weight_datetime', 'asc')
            ->select('bale_weight_datetime as event_datetime', 'bale_weight')
            ->get();

        return $this->generateWeightActivityTimeline($weightEvents, $start, 15);
    }

    private function generateActivityTimeline($events, $shiftStart, $onDurationMinutes, $counterColumn = null)
    {
        if ($events->isEmpty()) {
            return [['timestamp' => $shiftStart->toDateTimeString(), 'state' => 'off']];
        }

        $timeline = collect();

        $firstEventTime = Carbon::parse($events->first()->event_datetime);
        if ($firstEventTime->isAfter($shiftStart)) {
            $timeline->push(['timestamp' => $shiftStart->toDateTimeString(), 'state' => 'off']);
        }

        $lastEnd = null; // When machine should turn OFF
        $periodEvents = collect();
        $lastMaxCounter = null;

        foreach ($events as $event) {
            $onTime = Carbon::parse($event->event_datetime);
            $offTime = $onTime->copy()->addMinutes($onDurationMinutes);

            if (! $lastEnd || $onTime->greaterThan($lastEnd)) {
                // No overlap => push OFF before starting again
                if ($lastEnd && $periodEvents->isNotEmpty()) {
                    [$count, $lastMaxCounter] = $this->calculateCountFromCounters(
                        $periodEvents, $counterColumn, $lastMaxCounter
                    );
                    $timeline->push(['timestamp' => $lastEnd->toDateTimeString(), 'state' => 'off', 'count' => $count]);
                }
                $timeline->push(['timestamp' => $onTime->toDateTimeString(), 'state' => 'on']);

                $periodEvents = collect();
            }
            $periodEvents->push($event);

            // Extend ON/OFF duration if overlapping
            $lastEnd = $lastEnd ? $offTime->max($lastEnd) : $offTime;
        }

        // Final OFF after the last event timeout
        if ($periodEvents->isNotEmpty()) {
            [$count, $lastMaxCounter] = $this->calculateCountFromCounters(
                $periodEvents, $counterColumn, $lastMaxCounter
            );
            $timeline->push(['timestamp' => $lastEnd->toDateTimeString(), 'state' => 'off', 'count' => $count]);
        }

        return $timeline->all();
    }

    private function calculateCountFromCounters($events, $counterColumn, $lastMaxCounter = null)
    {
        if (! $counterColumn) {
            return [$events->count(), $lastMaxCounter]; // fallback to row count
        }

        $min = $events->min($counterColumn);
        $max = $events->max($counterColumn);

        if (is_null($lastMaxCounter)) {
            // First interval => max - min
            $count = max(0, $max - $min + 1);
        } else {
            // Subsequent intervals => max(current) - max(prev)
            $count = max(0, $max - $lastMaxCounter);
        }

        return [$count, $max];
    }

    private function generateWeightActivityTimeline($events, $shiftStart, $onDurationMinutes)
    {
        if ($events->isEmpty()) {
            return [['timestamp' => $shiftStart->toDateTimeString(), 'state' => 'off']];
        }

        $timeline = collect();

        $firstEventTime = Carbon::parse($events->first()->event_datetime);
        if ($firstEventTime->isAfter($shiftStart)) {
            $timeline->push(['timestamp' => $shiftStart->toDateTimeString(), 'state' => 'off']);
        }

        $lastEnd = null;
        $periodEvents = collect();

        foreach ($events as $event) {
            $onTime = Carbon::parse($event->event_datetime);
            $offTime = $onTime->copy()->addMinutes($onDurationMinutes);

            if (! $lastEnd || $onTime->greaterThan($lastEnd)) {
                // End of previous active period
                if ($lastEnd && $periodEvents->isNotEmpty()) {
                    $stats = $this->calculateWeightStats($periodEvents);
                    $timeline->push(array_merge(['timestamp' => $lastEnd->toDateTimeString(), 'state' => 'off'], $stats));
                }

                // Start new ON period
                $timeline->push(['timestamp' => $onTime->toDateTimeString(), 'state' => 'on']);
                $periodEvents = collect();
            }

            $periodEvents->push($event);
            $lastEnd = $lastEnd ? $offTime->max($lastEnd) : $offTime;
        }

        // Final OFF period after last event
        if ($periodEvents->isNotEmpty()) {
            $stats = $this->calculateWeightStats($periodEvents);
            $timeline->push(array_merge(['timestamp' => $lastEnd->toDateTimeString(), 'state' => 'off'], $stats));
        }

        return $timeline->all();
    }

    private function calculateWeightStats($events)
    {
        $count = $events->count();

        if ($count === 0) {
            return [
                'count' => 0,
                'under_weight' => 0,
                'efficient' => 0,
                'over_weight' => 0,
                'total_weight' => 0,
                'avg_weight' => 0,
            ];
        }

        $underWeight = $events->whereBetween('bale_weight', [100, 120])->count();
        $efficient = $events->whereBetween('bale_weight', [120, 140])->count();
        $overWeight = $events->whereBetween('bale_weight', [140, 160])->count();

        $totalWeight = round($events->sum('bale_weight'), 2);
        $avgWeight = round($totalWeight / $count, 2);

        return [
            'count' => $count,
            'under_weight' => $underWeight,
            'efficient' => $efficient,
            'over_weight' => $overWeight,
            'total_weight' => $totalWeight,
            'avg_weight' => $avgWeight,
        ];
    }

    private function resolveTimelineWindow($team, $date, $mode)
    {
        if ($mode === 'shift') {
            $shiftStartTime = $this->getShiftStartTime($team->id);

            $start = Carbon::parse($date)->setTimeFromTimeString($shiftStartTime);
            $end = $start->copy()->addDay()->subSecond();
        } else {
            // calendar mode
            $start = Carbon::parse($date)->startOfDay();
            $end = Carbon::parse($date)->endOfDay();
        }

        return [$start, $end];
    }

    private function getShiftStartTime($teamId)
    {
        return DB::table('pulse_shifts')
            ->where('team_id', $teamId)
            ->orderBy('start_time', 'asc')
            ->value('start_time') ?? '00:00:00';
    }
}
