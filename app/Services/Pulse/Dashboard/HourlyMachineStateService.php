<?php

namespace App\Services\Pulse\Dashboard;

use App\Models\Pulse\Device;
use App\Models\Pulse\Shift;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class HourlyMachineStateService
{
    public function get($startDate, $endDate)
    {
        if ($startDate->toDateString() !== $endDate->toDateString()) {
            return [];
        }

        $team = Auth::user()->currentTeam;
        $teamId = $team->id;

        if (Shift::activeForTeam($teamId)->exists()) {
            $startOfDay = Carbon::parse($startDate)->setTimeFromTimeString(Shift::anchorForTeam($teamId));
            $endOfDay = $startOfDay->copy()->addDay();
        } else {
            $startOfDay = Carbon::parse($startDate)->startOfDay();
            $endOfDay = Carbon::parse($startDate)->endOfDay();
        }

        // Devices for each module
        $defibreDeviceId = $this->resolveDeviceId($teamId, 'pith');
        $blocksDeviceId = $this->resolveDeviceId($teamId, 'blocks', $defibreDeviceId);
        $balingDeviceId = $this->resolveDeviceId($teamId, 'baling', $defibreDeviceId);

        $result = [
            'shift_start' => $startOfDay->toDateTimeString(),
            'shift_end' => $endOfDay->toDateTimeString(),
        ];

        // Shift still in progress today — tell the frontend how far we actually
        // have data, without touching the real shift boundaries.
        if ($endOfDay->isFuture()) {
            $result['current_time'] = Carbon::now()->toDateTimeString();
        }

        // Always include defibre (it's the base pith module) test
        $result['defibre'] = $defibreDeviceId
            ? $this->generateMachineStatesTimeline($defibreDeviceId, $startOfDay, $endOfDay)
            : [['timestamp' => $startOfDay->toDateTimeString(), 'state' => 'off']];

        // Include blocks/baling only if the submodule flag is enabled;
        // return an empty off-state if there's no active device yet
        if ($team->has_pith_module_blocks) {
            $result['blocks'] = $blocksDeviceId
                ? $this->generateBlocksTimeline($blocksDeviceId, $startDate->toDateString(), $startOfDay, $endOfDay)
                : [['timestamp' => $startOfDay->toDateTimeString(), 'state' => 'off']];
        }

        if ($team->has_pith_module_bales) {
            $result['baling'] = $balingDeviceId
                ? $this->generateBalingTimeline($balingDeviceId, $startDate->toDateString(), $startOfDay, $endOfDay)
                : [['timestamp' => $startOfDay->toDateTimeString(), 'state' => 'off']];
        }

        return $result;
    }

    private function generateMachineStatesTimeline($deviceId, $startOfDay, $endOfDay)
    {
        $records = DB::table('pulse_device_data')
            ->where('device_id', $deviceId)
            ->whereBetween('datetime', [$startOfDay, $endOfDay])
            ->select('datetime', 'd7')
            ->orderBy('datetime', 'asc')
            ->get();

        if ($records->isEmpty()) {
            return [['timestamp' => $startOfDay->toDateTimeString(), 'state' => 'off']];
        }

        $timeline = [];
        $lastState = null;

        // Ensure the timeline starts at shift start with an OFF state if needed
        $firstRecord = $records->first();
        $firstRecordTime = Carbon::parse($firstRecord->datetime);
        if ($firstRecordTime->greaterThan($startOfDay)) {
            $timeline[] = [
                'timestamp' => $startOfDay->toDateTimeString(),
                'state' => 'off',
            ];
            $lastState = 'off';
        }

        foreach ($records as $r) {
            $state = $r->d7 > 0 ? 'on' : 'off';
            $recordTime = Carbon::parse($r->datetime)->toDateTimeString();

            if ($lastState !== $state) {
                $timeline[] = [
                    'timestamp' => $recordTime,
                    'state' => $state,
                ];
                $lastState = $state;
            }
        }

        return $timeline;
    }

    private function generateBlocksTimeline($deviceId, $date, $startOfDay, $endOfDay)
    {
        $events = DB::table('pulse_device_data_pith_blocks')
            ->where('device_id', $deviceId)
            ->whereBetween('block_datetime', [$startOfDay, $endOfDay])
            ->where('is_duplicate', 0)
            ->orderBy('block_datetime', 'asc')
            ->select('block_datetime as event_datetime')
            ->get();

        return $this->generateActivityTimeline($events, $startOfDay, 5);
    }

    private function generateBalingTimeline($deviceId, $date, $startOfDay, $endOfDay)
    {
        $events = DB::table('pulse_device_data_fibre_bales')
            ->where('device_id', $deviceId)
            ->whereBetween('bale_datetime', [$startOfDay, $endOfDay])
            ->where('is_duplicate', 0)
            ->orderBy('bale_datetime', 'asc')
            ->select('bale_datetime as event_datetime')
            ->get();

        return $this->generateActivityTimeline($events, $startOfDay, 12);
    }

    private function generateActivityTimeline($events, $startOfDay, $onDurationMinutes)
    {
        if ($events->isEmpty()) {
            return [['timestamp' => $startOfDay->toDateTimeString(), 'state' => 'off']];
        }

        $timeline = collect();

        // Ensure timeline starts at shift start if there's a gap
        $firstEventTime = Carbon::parse($events->first()->event_datetime);
        if ($firstEventTime->greaterThan($startOfDay)) {
            $timeline->push(['timestamp' => $startOfDay->toDateTimeString(), 'state' => 'off']);
        }

        $lastEnd = null;

        foreach ($events as $event) {
            $onTime = Carbon::parse($event->event_datetime);
            $offTime = $onTime->copy()->addMinutes($onDurationMinutes);

            if (! $lastEnd || $onTime->greaterThan($lastEnd)) {
                if ($lastEnd) {
                    $timeline->push(['timestamp' => $lastEnd->toDateTimeString(), 'state' => 'off']);
                }
                $timeline->push(['timestamp' => $onTime->toDateTimeString(), 'state' => 'on']);
            }

            $lastEnd = $lastEnd ? $offTime->max($lastEnd) : $offTime;
        }

        $timeline->push(['timestamp' => $lastEnd->toDateTimeString(), 'state' => 'off']);

        return $timeline->all();
    }

    private function resolveDeviceId($teamId, $module, $fallbackDeviceId = null)
    {
        $deviceId = Device::where('team_id', $teamId)
            ->where('module', $module)
            ->where('status', 'active')
            ->value('device_id');

        return $deviceId ?: $fallbackDeviceId;
    }
}
