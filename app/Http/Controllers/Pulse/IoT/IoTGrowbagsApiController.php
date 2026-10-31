<?php

namespace App\Http\Controllers\Pulse\IoT;

use App\Http\Controllers\Controller;
use App\Models\Pulse\Device;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class IoTGrowbagsApiController extends Controller
{
    public function index(Request $request)
    {
        $team = Auth::user()->currentTeam;
        $currentTeamId = $team->id;

        $deviceIds = Device::where('team_id', $currentTeamId)
            ->where('module', 'growbags')
            ->where('status', 'active')
            ->pluck('device_id')
            ->filter()
            ->values();

        if ($deviceIds->isEmpty()) {
            return response()->json(['error' => 'No active growbags device found for team: '.$currentTeamId], 404);
        }

        $date = $request->input('start', now()->toDateString());
        $mode = $request->input('mode', 'calendar');

        $liveProduction = $this->fetchLiveProduction($team, $deviceIds, $date, $mode);

        return response()->json($liveProduction);
    }

    private function fetchLiveProduction($team, $deviceIds, $date, $mode)
    {
        // Resolve timeline window based on mode
        [$startOfTimeline, $endOfTimeline] =
        $this->resolveTimelineWindow($team, $date, $mode);

        [$calendarStart] = $this->resolveTimelineWindow($team, $date, 'calendar');

        [$shiftStart] = $this->resolveTimelineWindow($team, $date, 'shift');

        $machines = DB::table('pulse_master_growbag_machines')
            ->where('team_id', $team->id)
            ->where('is_active', 1)
            ->orderBy('field_id')
            ->get(['id', 'field_id', 'machine_name', 'bags_per_punch']);

        $events = DB::table('pulse_device_data_growbags')
            ->whereIn('device_id', $deviceIds)
            ->whereBetween('growbag_datetime', [$startOfTimeline, $endOfTimeline])
            ->where('is_duplicate', 0)
            ->orderBy('growbag_datetime', 'asc')
            ->select('growbag_datetime as event_datetime', 'growbags_counter', 'field_id')
            ->get()
            ->groupBy('field_id');

        $machinesPayload = [];
        $hasData = false;

        foreach ($machines as $machine) {

            $machineEvents = $events->get($machine->field_id, collect());
            $timeline = $this->generateActivityTimeline($machineEvents, $startOfTimeline, 10, 'growbags_counter');

            $timeline = collect($timeline)->map(function ($item) use ($machine) {
                if (isset($item['count'])) {
                    $item['count'] = $item['count'] * ($machine->bags_per_punch ?? 1);
                }

                return $item;
            })->values()->all();

            $machineTotal = collect($timeline)
                ->where('state', 'off')
                ->pluck('count')
                ->filter()
                ->sum();

            $machinesPayload[] = [
                'id' => $machine->id,
                'fieldId' => $machine->field_id,
                'name' => $machine->machine_name ?: 'Machine '.$machine->field_id,
                'timeline' => $timeline,
                'totalGrowbags' => $machineTotal,
            ];

            $isTimelineEmpty = $timeline == [] || count($timeline) === 1 && $timeline[0]['state'] === 'off';
            if (! $isTimelineEmpty) {
                $hasData = true;
            }
        }

        return [
            'machines' => $machinesPayload,
            'calendarStartTime' => $calendarStart->format('H:i'),
            'shiftStartTime' => $shiftStart->format('H:i'),
            'hasData' => $hasData,
        ];
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
