<?php

namespace App\Http\Controllers\Pulse\IoT;

use App\Http\Controllers\Controller;
use App\Models\Pulse\Device;
use App\Models\Team;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class IotPithApiController extends Controller
{
    public function index(Request $request)
    {
        $team = Auth::user()->currentTeam;
        $currentTeamId = $team->id;
        $deviceId = Device::where('team_id', $currentTeamId)
            ->where('module', 'pith')
            ->where('status', 'active')
            ->value('device_id');
        if (! $deviceId) {
            return response()->json(['error' => 'No active pith device found for team: '.$currentTeamId], 404);
        }

        $date = $request->input('start', now()->toDateString());
        $mode = $request->input('mode', 'calendar');

        $timelineData = $this->fetchLiveProduction($team, $deviceId, $date, $mode);

        return response()->json($timelineData);
    }

    public function fetchLiveProduction($team, $deviceId, $date, $mode)
    {
        // Resolve timeline window based on mode (for actual data generation)
        [$startOfTimeline, $endOfTimeline] = $this->resolveTimelineWindow($team, $date, $mode);

        // Resolve BOTH window starts (for toggle display only)
        [$calendarStart] =
            $this->resolveTimelineWindow($team, $date, 'calendar');

        [$shiftStart] =
            $this->resolveTimelineWindow($team, $date, 'shift');

        $machineStates = $this->generateMachineStatesTimeline($deviceId, $startOfTimeline, $endOfTimeline);

        $fibreData = [];
        $totalBales = 0;
        if ($team->has_pith_module_bales) {
            $fibreData = $this->generateFibreTimeline($deviceId, $startOfTimeline, $endOfTimeline);
            $totalBales = collect($fibreData)
                ->where('state', 'off')
                ->pluck('count')
                ->filter()
                ->sum();
        }

        $pithBlocks = [];
        $totalBlocks = 0;
        if ($team->has_pith_module_blocks) {
            $pithBlocks = $this->generatePithBlocksTimeline($team, $deviceId, $startOfTimeline, $endOfTimeline);
            $totalBlocks = collect($pithBlocks)
                ->where('state', 'off')
                ->pluck('count')
                ->filter()
                ->sum();
        }

        $production = DB::table('pulse_summary_pith_productions')
            ->where('team_id', $team->id)
            ->where('device_id', $deviceId)
            ->whereDate('shift_date', $date)
            ->selectRaw('COALESCE(SUM(cft_expected), 0) as cft_expected, COALESCE(SUM(cft_produced), 0) as cft_produced')
            ->first();

        $cftExpected = round((float) ($production->cft_expected ?? 0), 1);
        $cftProduced = round((float) ($production->cft_produced ?? 0), 1);
        $hasActuals = $cftProduced > 0.1;

        // Check if all three are empty (only contain the default 'off' state)
        $isMachineStatesEmpty = $machineStates == [] || count($machineStates) === 1 && $machineStates[0]['state'] === 'off';
        $isFibreDataEmpty = $fibreData == [] || count($fibreData) === 1 && $fibreData[0]['state'] === 'off';
        $isPithBlocksEmpty = $pithBlocks == [] || count($pithBlocks) === 1 && $pithBlocks[0]['state'] === 'off';

        $hasData = ! ($isMachineStatesEmpty && $isFibreDataEmpty && $isPithBlocksEmpty);

        return [
            'machineStates' => $machineStates,
            'fibreData' => $fibreData,
            'pithBlocks' => $pithBlocks,
            'totalBales' => $totalBales,
            'totalBlocks' => $totalBlocks,
            'cftExpected' => $cftExpected,
            'cftProduced' => $cftProduced,
            'hasActuals' => $hasActuals,
            'calendarStartTime' => $calendarStart->format('H:i'),
            'shiftStartTime' => $shiftStart->format('H:i'),
            'hasData' => $hasData,
        ];
    }

    private function generateMachineStatesTimeline($deviceId, Carbon $startOfTimeline, Carbon $endOfTimeline)
    {
        $device = Device::find($deviceId);

        $idleThreshold = $device->idle_th ?? 95;
        $efficientThreshold = $device->efficient_th ?? 230;

        $startOfDay = $startOfTimeline;
        $endOfDay = $endOfTimeline;

        $allRecords = DB::table('pulse_device_data')
            ->where('device_id', $deviceId)
            ->whereBetween('datetime', [$startOfDay, $endOfDay])
            ->select('datetime', 'd7')
            ->orderBy('datetime', 'asc')
            ->get();

        $machineStatesTimeline = [];

        if ($allRecords->isEmpty()) {
            return [[
                'timestamp' => $startOfDay->toDateTimeString(),
                'state' => 'off',
            ]];
        }

        $lastStateForTimeline = null;
        $intervalStart = $startOfDay->copy();

        while ($intervalStart->lessThan($endOfDay)) {
            $intervalEnd = $intervalStart->copy()->addMinutes(5);
            if ($intervalEnd->greaterThan($endOfDay)) {
                $intervalEnd = $endOfDay->copy();
            }

            $recordsInInterval = $allRecords->where('datetime', '>=', $intervalStart->toDateTimeString())
                ->where('datetime', '<', $intervalEnd->toDateTimeString());

            if ($recordsInInterval->isEmpty()) {
                $dominantState = $lastStateForTimeline ?? 'off';
            } else {
                $durationTotals = ['on' => 0, 'off' => 0];
                $currentTime = $intervalStart->copy();

                // Initialize prevState from first record
                $firstRecord = $recordsInInterval->first();
                $prevState = ($firstRecord->d7 == 0) ? 'off' : 'on';

                foreach ($recordsInInterval as $record) {
                    $recordTime = Carbon::parse($record->datetime);
                    $State = ($record->d7 == 0) ? 'off' : 'on';

                    if ($recordTime->isAfter($currentTime)) {
                        $durationTotals[$prevState] += $currentTime->diffInSeconds($recordTime);
                    }

                    $currentTime = $recordTime;
                    $prevState = $State;
                }

                // Add remaining duration till end of interval to prevState
                if ($intervalEnd->greaterThan($currentTime)) {
                    $durationTotals[$prevState] += $currentTime->diffInSeconds($intervalEnd);
                }

                $dominantState = ($durationTotals['off'] >= $durationTotals['on']) ? 'off' : 'on';
            }

            if (is_null($lastStateForTimeline) || $dominantState !== $lastStateForTimeline) {
                $machineStatesTimeline[] = [
                    'timestamp' => $intervalStart->toDateTimeString(),
                    'state' => $dominantState,
                ];
                $lastStateForTimeline = $dominantState;
            }

            $intervalStart->addMinutes(5);
        }

        return $machineStatesTimeline;
    }

    private function generateFibreTimeline($deviceId, Carbon $startOfTimeline, Carbon $endOfTimeline)
    {
        $fibreEvents = DB::table('pulse_device_data_fibre_bales')
            ->where('device_id', $deviceId)
            ->whereBetween('bale_datetime', [$startOfTimeline, $endOfTimeline])
            ->where('is_duplicate', 0)
            ->orderBy('bale_datetime', 'asc')
            ->select('bale_datetime as event_datetime', 'bales_counter')
            ->get();

        return $this->generateActivityTimeline($fibreEvents, $startOfTimeline, $endOfTimeline, 15, 'bales_counter');
    }

    private function generatePithBlocksTimeline($team, $deviceId, Carbon $startOfTimeline, Carbon $endOfTimeline)
    {
        $blockEvents = DB::table('pulse_device_data_pith_blocks')
            ->where('device_id', $deviceId)
            ->whereBetween('block_datetime', [$startOfTimeline, $endOfTimeline])
            ->where('is_duplicate', 0)
            ->orderBy('block_datetime', 'asc')
            ->select('block_datetime as event_datetime', 'blocks_counter')
            ->get();

        return $this->generateActivityTimeline($blockEvents, $startOfTimeline, $endOfTimeline, 10, 'blocks_counter');
    }

    private function generateActivityTimeline($events, Carbon $startOfTimeline, Carbon $endOfTimeline, $onDurationMinutes, $counterColumn = null)
    {
        if ($events->isEmpty()) {
            return [['timestamp' => $startOfTimeline->toDateTimeString(), 'state' => 'off']];
        }

        $timeline = collect();

        $firstEventTime = Carbon::parse($events->first()->event_datetime);
        if ($firstEventTime->isAfter($startOfTimeline)) {
            $timeline->push(['timestamp' => $startOfTimeline->toDateTimeString(), 'state' => 'off']);
        }

        $lastEnd = null; // When machine should turn OFF
        $periodEvents = collect();
        $lastMaxCounter = null;

        foreach ($events as $event) {
            $onTime = Carbon::parse($event->event_datetime);
            $offTime = $onTime->copy()->addMinutes($onDurationMinutes);

            // Clamp OFF time to timeline end
            if ($offTime->greaterThan($endOfTimeline)) {
                $offTime = $endOfTimeline->copy();
            }

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
        // Fetch the earliest shift start time for this team
        return DB::table('pulse_shifts')
            ->where('team_id', $teamId)
            ->orderBy('start_time', 'asc')
            ->value('start_time') ?? '00:00:00'; // Fallback to midnight
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
}
