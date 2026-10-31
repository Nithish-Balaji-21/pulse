<?php

namespace App\Jobs\Pulse;

use App\Models\Pulse\DeviceData;
use App\Models\Pulse\DeviceDataState;
use App\Models\Pulse\PithProduction;
use App\Models\Pulse\Shift;
use App\Services\Pulse\ProductionSummaryService;
use App\Traits\RecordsJobCompletion;
use App\Traits\RetriesWithJitteredBackoff;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessDeviceDataForPithJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RecordsJobCompletion, RetriesWithJitteredBackoff, SerializesModels;

    protected int $teamId;

    protected int $deviceId;

    protected array $dates;

    public function __construct(int $teamId, int $deviceId, array $dates)
    {
        $this->teamId = $teamId;
        $this->deviceId = $deviceId;
        $this->dates = $dates;
    }

    public function tags(): array
    {
        return ['team:'.$this->teamId];
    }

    public function handle()
    {
        $startTime = microtime(true);

        // Fetch the shifts for the team, ordered within the shift day
        $anchor = Shift::anchorForTeam($this->teamId);
        $shifts = Shift::where('team_id', $this->teamId)
            ->orderedByOffset($anchor)
            ->get();

        // If single day, then get all the respective dates from the given date
        $allDates = $this->dates;
        if (count($this->dates) <= 1) {
            $allDates = $this->getAllDatesFromGivenDate($this->dates, $anchor);
        }

        $settings = DB::table('pulse_settings')
            ->whereIn('key', ['EB_UNITS_PER_CFT', 'RS_PER_EB_UNIT'])
            ->where('team_id', $this->teamId)
            ->pluck('value', 'key');

        $ebUnitsPerLoad = $settings['EB_UNITS_PER_CFT'] ?? null;
        $ebPriceValue = $settings['RS_PER_EB_UNIT'] ?? null;

        $insert_queries_pith = [];
        $pith = new ProcessDeviceDataForStates($this->teamId, $this->deviceId, $ebPriceValue);

        foreach ($allDates as $date) {

            $startOfDay = Carbon::createFromFormat('Y-m-d', $date)
                ->setTimeFromTimeString($anchor)
                ->toDateTimeString();

            $endOfDay = Carbon::createFromFormat('Y-m-d', $date)->addDay()
                ->setTimeFromTimeString($anchor)->subSecond()
                ->toDateTimeString();

            $deviceDataRecords = DeviceData::whereBetween('datetime', [$startOfDay, $endOfDay])
                ->where('device_id', $this->deviceId)
                ->orderBy('datetime')
                ->get();

            if ($deviceDataRecords->isNotEmpty()) {
                DeviceDataState::where('device_id', $this->deviceId)
                    ->whereBetween('start_time', [$startOfDay, $endOfDay])
                    ->delete();
            }

            $queries = $pith->processDeviceData($deviceDataRecords, $startOfDay, $endOfDay);
            $insert_queries_pith = array_merge($insert_queries_pith, $queries);
        }

        $this->batchInsert('pulse_summary_power_states', $insert_queries_pith);

        Log::info('Process #1 (device states insert) completed. Time taken: '.(microtime(true) - $startTime));

        foreach ($allDates as $date) {
            foreach ($shifts as $shift) {
                $this->processPithProduction($date, $shift, $ebUnitsPerLoad, $ebPriceValue);
            }
        }

        Log::info('Process #2 (pith production insert) completed. Total Time taken: '.(microtime(true) - $startTime));

        // Push notification
        $yesterday = Carbon::yesterday()->format('Y-m-d');
        if (count($this->dates) !== 1 || $this->dates[0] !== $yesterday) {
            Log::info("Skipping summary notification for Team ID {$this->teamId} on ".($this->dates[0] ?? 'no date').' as it is not yesterday.');

            return;
        }

        // Prepare and send email and push notification
        $service = new ProductionSummaryService($this->teamId);
        $mainModuleSummary = $service->getPithLoadsSummaryByShift($yesterday);

        $subject = 'Yesterday ('.Carbon::parse($yesterday)->format(config('app.day_only_format', 'D')).')';
        $service->buildSummaryAndNotify($mainModuleSummary, $subject, $yesterday);

        Log::info("Sent summary notification for Team {$this->teamId} on {$yesterday}.");
    }

    protected function processPithProduction($shiftDate, $shift, $ebUnitsPerLoad, $ebPriceValue)
    {
        // Ensure shiftDate is a string date.
        $shiftDate = Carbon::parse($shiftDate)->toDateString();

        // Calculate shift start and end using a helper method.
        [$shiftStart, $shiftEnd] = $this->calculateShiftWindow($shiftDate, $shift);

        // Check if a production record already exists.
        $existingProduction = PithProduction::where('team_id', $this->teamId)
            ->where('shift_id', $shift->id)
            ->where('shift_date', $shiftDate)
            ->first();

        if ($existingProduction) {
            $existStartTime = Carbon::parse($existingProduction->start_time);
            $existEndTime = Carbon::parse($existingProduction->end_time);
        } else {
            $existStartTime = $shiftStart;
            $existEndTime = $shiftEnd;
        }

        // Aggregate EB units for the shift period.
        $aggregatedEbUnits = DB::table('pulse_summary_power_states')
            ->where('device_id', $this->deviceId)
            ->where(function ($query) use ($existStartTime, $existEndTime) {
                $query->whereBetween('start_time', [$existStartTime, $existEndTime])
                    ->orWhereBetween('end_time', [$existStartTime, $existEndTime])
                    ->orWhere(function ($query) use ($existStartTime, $existEndTime) {
                        $query->where('start_time', '<', $existStartTime)
                            ->where('end_time', '>', $existEndTime);
                    });
            })
            ->get()
            ->map(function ($record) use ($existStartTime, $existEndTime) {
                // Calculate overlap duration.
                $recordStart = strtotime($record->start_time);
                $recordEnd = strtotime($record->end_time);
                $overlapStart = max($recordStart, $existStartTime->timestamp);
                $overlapEnd = min($recordEnd, $existEndTime->timestamp);
                $overlapDuration = max(0, $overlapEnd - $overlapStart);
                $totalDuration = $recordEnd - $recordStart;
                $recordEbUnits = is_numeric($record->eb_units) ? (float) $record->eb_units : 0;

                return ($totalDuration > 0)
                    ? ($overlapDuration / $totalDuration) * $recordEbUnits
                    : 0;
            })
            ->sum();

        // Skip if the aggregated EB units are less than or equal to 20.
        if ($aggregatedEbUnits <= 20) {
            Log::warning("Skipping Team {$this->teamId}: device {$this->deviceId}: on {$shiftDate}: at {$shift->name} due to low EB units ({$aggregatedEbUnits}).");

            return;
        }

        // Calculate production amounts.
        $ebAmount = round($aggregatedEbUnits * $ebPriceValue, 1);
        $loadsExpected = ($ebUnitsPerLoad > 0 ? round($aggregatedEbUnits / $ebUnitsPerLoad) : 0);

        if ($existingProduction) {
            // Update the production record.
            $existingProduction->update([
                'eb_units' => round($aggregatedEbUnits, 1),
                'eb_price' => $ebAmount,
                'cft_expected' => $loadsExpected,
            ]);

            Log::info("Updated production record: Team {$this->teamId}, Device {$this->deviceId}, Shift {$shift->name}, ".
                "Shift Date: {$shiftDate} (using existing start_time and end_time), ".
                "Loads Expected: {$loadsExpected}, EB Units: ".round($aggregatedEbUnits, 1).", EB Price: {$ebAmount}");
        } else {
            // Create a new production record.
            PithProduction::create([
                'team_id' => $this->teamId,
                'shift_id' => $shift->id,
                'shift_date' => $shiftDate,
                'device_id' => $this->deviceId,
                'shift_name' => $shift->name,
                'start_time' => $shiftStart,
                'end_time' => $shiftEnd,
                'eb_units' => round($aggregatedEbUnits, 1),
                'eb_price' => $ebAmount,
                'cft_expected' => $loadsExpected,
            ]);

            Log::info("Created production record: Team {$this->teamId}, Device {$this->deviceId}, {$shift->name}, ".
                "Shift Date: {$shiftDate}, Start: {$shiftStart->toDateTimeString()}, End: {$shiftEnd->toDateTimeString()}, ".
                "Loads Expected: {$loadsExpected}, EB Units: ".round($aggregatedEbUnits, 1).", EB Price: {$ebAmount}");
        }
    }

    /**
     * Calculate shift start and end times given a shift date and shift configuration.
     *
     * @param  string  $shiftDate
     * @return array [Carbon $start, Carbon $end]
     */
    protected function calculateShiftWindow($shiftDate, $shift)
    {
        return $shift->windowOn($shiftDate);
    }

    protected function getAllDatesFromGivenDate($dates, $anchor)
    {
        // If no dates are provided, return yesterday's date
        if (empty($dates)) {
            return [Carbon::yesterday()->format('Y-m-d')];
        }

        $start = Carbon::parse($dates[0])->setTimeFromTimeString($anchor);
        $end = $start->copy()->addDay()->subSecond();

        $deviceData = DB::table('pulse_device_data')
            ->select(DB::raw("
                DISTINCT CASE
                    WHEN TIME(datetime) < '{$anchor}'
                    THEN DATE_SUB(DATE(datetime), INTERVAL 1 DAY)
                    ELSE DATE(datetime)
                END AS adjusted_date
            "))
            ->where('device_id', $this->deviceId)
            ->whereBetween('created_at', [$start, $end])
            ->pluck('adjusted_date');

        $dates = $deviceData->unique()->values()->all();
        if (! empty($dates)) {
            return $dates;
        }

        return [Carbon::yesterday()->format('Y-m-d')];
    }

    protected function batchInsert($table, $data, $batchSize = 500)
    {
        foreach (array_chunk($data, $batchSize) as $batch) {
            DB::table($table)->insertOrIgnore($batch);
        }
    }

    public function getTeamId(): int
    {
        return $this->teamId;
    }
}
