<?php

namespace App\Jobs\Pulse;

use App\Models\Pulse\WaterMeterConsumption;
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

class ProcessDeviceDataForModWaterJob implements ShouldQueue
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
        // Clear all previously marked duplicates
        $this->deleteAllDuplicateEntries();

        // Mark duplicates using SQL window function
        $this->markAllDuplicateEntries();

        // Process production for all discovered dates
        $discoveredDates = [];

        foreach ($this->dates as $date) {
            $discoveredDates = array_merge(
                $discoveredDates,
                $this->getAllDatesFromDate($date)
            );
        }

        // Deliberately a local, not a write back to $this->dates: the job-run ledger
        // closes on ledgerRunDate(), which reads that property after handle() returns.
        // Overwriting it with the discovered dates left the nightly entry open whenever
        // discovery found no dates, or dates other than the single one dispatched.
        $targetDates = array_values(array_unique($discoveredDates));

        if ($targetDates === []) {
            // Two very different causes land here, so the message must not accuse the
            // device: either it genuinely uploaded nothing, or the plant was idle, the
            // counter never moved, and the duplicate sweep above removed every flat
            // reading of the day (docs/iot/water.md steps 4.1-4.2) — leaving a healthy
            // meter looking silent. Either way the job did its work, so it closes its
            // ledger entry and stays out of the missed-run email.
            // Distinct from the per-field "No water meter data available" line below,
            // which fires on a day that has readings, just not for that field.
            Log::warning(sprintf(
                'WATER METER NO DATA: no readings remain for team %d, device %d on %s '
                .'— the meter uploaded nothing, or this is a re-run of a day whose flat '
                .'readings the duplicate sweep has already removed.',
                $this->teamId,
                $this->deviceId,
                implode(', ', $this->dates)
            ));

            return;
        }

        foreach ($targetDates as $date) {
            foreach ($this->meterFieldIds() as $fieldId) {
                $this->populateWaterMeterConsumption($date, $fieldId);
            }
        }
    }

    protected function populateWaterMeterConsumption($date, $fieldId)
    {
        $entry = DB::selectOne('
            SELECT
                f.first_counter,
                l.last_counter
            FROM
                (
                    SELECT litres_counter AS first_counter
                    FROM pulse_device_data_water_meters
                    WHERE device_id = ? AND field_id = ? AND meter_date < ? AND is_duplicate = 0
                    ORDER BY meter_datetime DESC
                    LIMIT 1
                ) AS f
            JOIN
                (
                    SELECT litres_counter AS last_counter
                    FROM pulse_device_data_water_meters
                    WHERE device_id = ? AND field_id = ? AND meter_date = ? AND is_duplicate = 0
                    ORDER BY meter_datetime DESC
                    LIMIT 1
                ) AS l ON 1=1
        ', [$this->deviceId, $fieldId, $date, $this->deviceId, $fieldId, $date]);

        if (! $entry || $entry->first_counter === null || $entry->last_counter === null) {
            Log::info("No water meter data available on {$date} for device {$this->deviceId}, field {$fieldId}");
            $total = 0;
        } else {
            $total = $entry->last_counter - $entry->first_counter;

            // Fallback to detailed reading in case of counter reset
            if ($total < 0) {
                Log::warning("Counter reset detected for water meter on {$date}, device {$this->deviceId}, field {$fieldId}");

                $readings = DB::table('pulse_device_data_water_meters')
                    ->select('litres_counter as counter')
                    ->where('device_id', $this->deviceId)
                    ->where('field_id', $fieldId)
                    ->where('meter_date', $date)
                    ->orderBy('meter_datetime')
                    ->get();

                $prev = null;
                $total = 0;

                foreach ($readings as $reading) {
                    $curr = (int) $reading->counter;
                    $total += $this->calculateCounter($prev, $curr);
                    $prev = $curr;
                }
            }
        }

        $this->createOrUpdateProduction($date, $fieldId, $total);
    }

    protected function createOrUpdateProduction($date, $fieldId, $total)
    {
        WaterMeterConsumption::upsert(
            [
                [
                    'team_id' => $this->teamId,
                    'device_id' => $this->deviceId,
                    'field_id' => $fieldId,
                    'date' => $date,
                    'litres_consumed' => $total,
                ],
            ],
            ['team_id', 'device_id', 'field_id', 'date'], // Unique constraint columns
            ['litres_consumed']   // Columns to update if exists
        );

        Log::info("Upserted water meter consumption record: Team {$this->teamId}, Device {$this->deviceId}, Field {$fieldId}, Date: {$date}, Water consumed: {$total}");
    }

    protected function calculateCounter(?int $previous, int $current): int
    {
        // First reading in the period → assume a 10L minimum consumption baseline
        // (deliberately different from the other modules' "first reading = 1 unit"
        // logic; see docs/iot/water.md step 4.5).
        if (is_null($previous)) {
            return 10;
        }

        // normal increment
        if ($current >= $previous) {
            return $current - $previous;
        }

        // counter reset → assume 0
        return 0;
    }

    /**
     * The device field ids that carry a litres counter.
     *
     * `app.water_consumption` mixes these with the daily/hourly threshold values, so
     * taking array_values() of the whole group treated those thresholds as field ids
     * and wrote an empty summary row per day for each. Every other consumer of this
     * config reads a named key; this names them too.
     *
     * @return list<string>
     */
    protected function meterFieldIds(): array
    {
        return [
            config('app.water_consumption.total'),
            config('app.water_consumption.d16'),
        ];
    }

    protected function getAllDatesFromDate($date)
    {
        // If no dates are provided, return yesterday's date
        if (empty($date)) {
            return [Carbon::yesterday()->format('Y-m-d')];
        }

        $start = Carbon::parse($date)->startOfDay();
        $end = Carbon::parse($date)->endOfDay();

        $deviceData = DB::table('pulse_device_data_water_meters')
            ->select(DB::raw('
                DISTINCT meter_date AS dates
            '))
            ->where('device_id', $this->deviceId)
            ->whereIn('field_id', $this->meterFieldIds())
            ->whereBetween('created_at', [$start, $end])
            ->pluck('dates');

        $dates = $deviceData->unique()->values()->all();
        if (! empty($dates)) {
            return $dates;
        }

        return [];
    }

    private function markAllDuplicateEntries()
    {
        DB::statement('
            WITH ordered AS (
                SELECT
                    id,
                    device_id,
                    litres_counter,
                    meter_datetime,
                    LAG(litres_counter) OVER (
                        PARTITION BY device_id, field_id
                        ORDER BY meter_datetime
                    ) AS prev_counter
                FROM pulse_device_data_water_meters
                WHERE device_id = ?
                  AND meter_datetime < NOW()
            )
            UPDATE pulse_device_data_water_meters r
            JOIN ordered o ON r.id = o.id
            SET r.is_duplicate = 1
            WHERE o.prev_counter = o.litres_counter
        ', [$this->deviceId]);

        Log::info("Marked duplicate entries using window function for device {$this->deviceId}");
    }

    private function deleteAllDuplicateEntries()
    {
        $deleted = DB::table('pulse_device_data_water_meters')
            ->where('device_id', $this->deviceId)
            ->whereIn('field_id', $this->meterFieldIds())
            ->where('is_duplicate', 1)
            ->delete();

        if ($deleted > 0) {
            Log::info("Deleted {$deleted} previously marked duplicate entries for device {$this->deviceId}");
        }
    }

    public function getTeamId(): int
    {
        return $this->teamId;
    }
}
