<?php

namespace App\Jobs\Pulse;

use App\Models\Pulse\Device;
use App\Services\Pulse\DefibreStateClassifier;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ProcessDeviceDataForStates
{
    // Properties to hold threshold values
    protected $offThreshold;

    protected $idleThreshold;

    protected $efficientThreshold;

    protected $missingThresholdDuration;

    protected $deviceMsgDuration;

    protected $deviceEbUnit;

    protected $ebPricePerUnit;

    protected $teamId;

    protected $deviceId;

    protected $ebMeterReading = 0;

    const POWER_OFF = 'shutdown';

    const DEVICE_STATE_OFF = 'off';

    const DEVICE_STATE_IDLE = 'idle';

    const DEVICE_STATE_EFFICIENT = 'efficient';

    const DEVICE_STATE_OVERLOAD = 'overload';

    const DEVICE_STATE_MISSING = 'missing';

    public function __construct($teamId, $deviceId, $ebPricePerUnit)
    {
        $this->teamId = $teamId;
        $this->deviceId = $deviceId;

        // Set thresholds from device settings
        $device = Device::where('device_id', $deviceId)->first();

        $this->offThreshold = $device->off_th ?? 10;
        $this->idleThreshold = $device->idle_th ?? 95;
        $this->efficientThreshold = $device->efficient_th ?? 230;
        $this->missingThresholdDuration = $device->duration_device_missing ?? 65;
        $this->deviceMsgDuration = $device->duration_device_msg ?? 20;
        $this->deviceEbUnit = $device->eb_units ?? 'kWh';
        $this->ebPricePerUnit = $ebPricePerUnit ?? 10.15;
    }

    public function processDeviceData($records, $startOfDay, $endOfDay)
    {
        $insertQueries = [];

        $this->processDeviceRecords($records, $startOfDay, $endOfDay, $insertQueries);

        return $insertQueries;
    }

    protected function processDeviceRecords($records, $startOfDay, $endOfDay, &$insertQueries)
    {
        $previousState = null;
        $stateStartTime = $startOfDay;
        $previousTimestamp = null;

        // Reset ebMeterValue at the beginning of each day's processing
        $this->ebMeterReading = 0;
        $previousDataEb = 0;

        foreach ($records as $data) {
            $timestamp = $data->datetime;

            if ($previousTimestamp === null) {
                // Check for missing data at the start of the day
                $initialMissing = strtotime($timestamp) - strtotime($startOfDay);
                if ($initialMissing > $this->missingThresholdDuration) {

                    $this->ebMeterReading = DB::table('pulse_device_data_daily_summary')
                        ->where('device_id', $this->deviceId)
                        ->where('date', Carbon::parse($startOfDay)->format('Y-m-d'))
                        ->value('last_d15');

                    $this->handleMissingDataForStartOfDay($startOfDay, $timestamp, $data->d15, $insertQueries);
                }

                $this->ebMeterReading = $data->d15;
            } else {
                // Detect missing data intervals in between the shift day
                if ((strtotime($timestamp) - strtotime($previousTimestamp) > $this->missingThresholdDuration)) {
                    $this->handleMissingData($data, $startOfDay, $previousState, $stateStartTime, $previousTimestamp, $timestamp, $previousDataEb, $insertQueries);
                    $stateStartTime = $timestamp;
                }
            }

            // Determine the current state
            $deviceState = $this->categorizePowerData($data->d7, $data->d4 + $data->d5 + $data->d6);

            if ($previousState === null) {
                // Initialize the first state
                $previousState = $deviceState;
                $stateStartTime = (strtotime($timestamp) - strtotime($startOfDay) > $this->missingThresholdDuration) ? $timestamp : $startOfDay;
            } elseif ($deviceState !== $previousState) {
                // Process state changes
                $duration = strtotime($timestamp) - strtotime($stateStartTime);
                if ($duration > 0) {
                    $this->prepareDeviceStateInsert($startOfDay, $previousState, $stateStartTime, $timestamp, $data->d15, $insertQueries);
                    $stateStartTime = $timestamp;
                }
                $previousState = $deviceState;
            }

            $previousDataEb = $data->d15;
            $previousTimestamp = $timestamp;
        }

        // Insert the last state
        if ($previousState) {
            $previousTimestamp = date('Y-m-d H:i:s', strtotime($previousTimestamp.' +'.min($this->deviceMsgDuration, strtotime($endOfDay) - strtotime($previousTimestamp)).' seconds'));
            $this->prepareDeviceStateInsert($startOfDay, $previousState, $stateStartTime, $previousTimestamp, $records->last()->d15, $insertQueries);
        }

        // Handle any final missing data at the end of the day
        if ($previousTimestamp) {
            if ((strtotime($endOfDay) - strtotime($previousTimestamp) > $this->missingThresholdDuration) || ($previousTimestamp < $endOfDay)) {
                $this->handleFinalMissingData($startOfDay, $previousTimestamp, $endOfDay, $insertQueries);
            }

            DB::table('pulse_device_data_daily_summary')->upsert(
                [
                    [
                        'device_id' => $this->deviceId,
                        'date' => Carbon::parse($startOfDay)->format('Y-m-d'),
                        'last_d15' => optional($records->last())->d15 ?? 0,
                    ],
                ],
                ['device_id', 'date'], // Unique constraint columns
                ['last_d15']           // Columns to update if exists
            );
        }

    }

    protected function handleMissingDataForStartOfDay($startOfDay, $firstRecordTimestamp, $ebUnits, &$insertQueries)
    {
        // Handle missing data before the first record of the day
        $this->prepareDeviceStateInsert(
            $startOfDay,
            self::DEVICE_STATE_MISSING,
            $startOfDay,
            date('Y-m-d H:i:s', strtotime($firstRecordTimestamp)),
            $ebUnits,
            $insertQueries
        );
    }

    protected function handleMissingData($data, $startOfDay, $previousState, $stateStartTime, $previousTimestamp, $timestamp, $lastDataD15, &$insertQueries)
    {
        $stateEndTime = date('Y-m-d H:i:s', strtotime($previousTimestamp.' +'.$this->deviceMsgDuration.' seconds'));

        // Save previous state before storing missing data
        $this->prepareDeviceStateInsert(
            $startOfDay,
            $previousState,
            $stateStartTime,
            $stateEndTime,
            $lastDataD15,
            $insertQueries
        );

        // Save missing state for missing period
        if ($stateEndTime < $timestamp) {
            $this->prepareDeviceStateInsert(
                $startOfDay,
                self::DEVICE_STATE_MISSING,
                $stateEndTime,
                $timestamp,
                $data->d15,
                $insertQueries
            );
        }

        // Reset the state start time
        $stateStartTime = $timestamp;
    }

    protected function handleFinalMissingData($startOfDay, $previousTimestamp, $endOfDay, &$insertQueries)
    {
        $this->prepareDeviceStateInsert(
            $startOfDay,
            self::DEVICE_STATE_MISSING,
            $previousTimestamp,
            $endOfDay,
            0,
            $insertQueries
        );
    }

    protected function categorizePowerData($current, $voltage)
    {
        if (DefibreStateClassifier::isShutdown($current, $voltage)) {
            return self::POWER_OFF;
        } elseif ($current < $this->offThreshold) {
            return self::DEVICE_STATE_OFF;
        } elseif ($current < $this->idleThreshold) {
            return self::DEVICE_STATE_IDLE;
        } elseif ($current < $this->efficientThreshold) {
            return self::DEVICE_STATE_EFFICIENT;
        } else {
            return self::DEVICE_STATE_OVERLOAD;
        }
    }

    protected function prepareDeviceStateInsert($startOfDay, $state, $startTime, $endTime, $ebUnits, &$insertQueries)
    {
        // Logic to calculate eb units consumed
        if ($this->ebMeterReading === 0 && $ebUnits > 0) {
            $this->ebMeterReading = $ebUnits;
        }
        $ebUnitsFinal = max(0, $ebUnits - $this->ebMeterReading);
        if ($ebUnits > 0) {
            $this->ebMeterReading = $ebUnits;
        }

        // If device support watts (ex: Alam), convert it to kWh
        if ($this->deviceEbUnit === 'watts') {
            $ebUnitsFinal = $ebUnitsFinal / 1000;
        }
        $ebUnitsFinal = $ebUnitsFinal > 9999 ? 0 : $ebUnitsFinal;

        $startTimeOnly = substr($startTime, 11); // Extracts 'HH:MM:SS'
        $endTimeOnly = substr($endTime, 11);

        $startTimeTimestamp = strtotime($startTime);
        $endTimeTimestamp = strtotime($endTime);

        // If startTime > endTime (based on time only), the record spans across midnight
        if ($startTimeOnly > $endTimeOnly) {
            $firstEndTime = substr($startTime, 0, 10).' 23:59:59'; // End of the first day
            $secondStartTime = substr($endTime, 0, 10).' 00:00:00';

            $firstEndTimeTimestamp = strtotime($firstEndTime);
            $secondStartTimeTimestamp = strtotime($secondStartTime);

            // Durations for each part
            $firstDuration = $firstEndTimeTimestamp - $startTimeTimestamp;
            $secondDuration = $endTimeTimestamp - $secondStartTimeTimestamp;
            $totalDuration = $firstDuration + $secondDuration;

            // Distribute EB units proportionally
            $firstEbUnits = ($totalDuration > 0) ? ($ebUnitsFinal * ($firstDuration / $totalDuration)) : 0;
            $secondEbUnits = $ebUnitsFinal - $firstEbUnits; // Ensures sum remains consistent

            // First record (before midnight)
            $insertQueries[] = [
                'team_id' => $this->teamId,
                'device_id' => $this->deviceId,
                'date' => substr($startTime, 0, 10),
                'shift_date' => substr($startOfDay, 0, 10),
                'start_time' => $startTime,
                'end_time' => $firstEndTime,
                'duration' => strtotime($firstEndTime) - strtotime($startTime),
                'state' => $state,
                'eb_units' => $firstEbUnits,
                'eb_price' => ($firstEbUnits * $this->ebPricePerUnit),
            ];

            $insertQueries[] = [
                'team_id' => $this->teamId,
                'device_id' => $this->deviceId,
                'date' => substr($secondStartTime, 0, 10),
                'shift_date' => substr($startOfDay, 0, 10),
                'start_time' => $secondStartTime,
                'end_time' => $endTime,
                'duration' => strtotime($endTime) - strtotime($secondStartTime),
                'state' => $state,
                'eb_units' => $secondEbUnits,
                'eb_price' => ($secondEbUnits * $this->ebPricePerUnit),
            ];

            return;
        }

        // If no split is needed, return a single record
        $insertQueries[] = [
            'team_id' => $this->teamId,
            'device_id' => $this->deviceId,
            'date' => substr($startTime, 0, 10),
            'shift_date' => substr($startOfDay, 0, 10),
            'start_time' => $startTime,
            'end_time' => $endTime,
            'duration' => $endTimeTimestamp - $startTimeTimestamp,
            'state' => $state,
            'eb_units' => $ebUnitsFinal,
            'eb_price' => ($ebUnitsFinal * $this->ebPricePerUnit),
        ];
    }
}
