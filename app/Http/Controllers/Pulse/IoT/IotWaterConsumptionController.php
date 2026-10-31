<?php

namespace App\Http\Controllers\Pulse\IoT;

use App\Http\Controllers\Controller;
use App\Models\Pulse\Device;
use App\Services\NumberFormatterService;
use App\Support\Pulse\IotLiveDay;
use App\Traits\ModuleRedirectTrait;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class IotWaterConsumptionController extends Controller
{
    use ModuleRedirectTrait;

    /**
     * @var list<int>
     */
    private const BUCKET_SECONDS = [60, 120, 300, 600, 900, 1800, 3600, 7200];

    private const DENSITY_TRACK_ALPHA = 0.10;

    public function index(Request $request)
    {
        $team = Auth::user()->currentTeam;
        if ($redirect = $this->handleMissingModules('has_water_meter_module')) {
            return $redirect;
        }

        $fieldId = $request->query('field_id') ?: config('app.water_consumption.total');
        $today = now()->toDateString();
        $waterColors = config('colors.water');
        $liveDay = IotLiveDay::today();

        return view('pulse::iot-insights.water.weekly_water', array_merge(
            compact('today', 'waterColors', 'fieldId', 'liveDay'),
            ['teamId' => Auth::user()->currentTeam->id]
        ));
    }

    public function fetchEventsByDate(Request $request)
    {
        $startDate = Carbon::parse($request->query('start'))->format('Y-m-d');
        $endDate = Carbon::parse($request->query('end'))->format('Y-m-d');

        $currentTeamId = Auth::user()->currentTeam->id;

        // Prefer the water-meter device — a pith/fibre device on the same team would
        // otherwise make live raw overlays (and density marks) miss the water stream.
        $deviceId = DB::table('pulse_master_water_meters')
            ->join('pulse_devices', 'pulse_devices.device_id', '=', 'pulse_master_water_meters.device_id')
            ->where('pulse_devices.team_id', $currentTeamId)
            ->where('pulse_devices.status', 'active')
            ->value('pulse_master_water_meters.device_id')
            ?: Device::where('team_id', $currentTeamId)->where('status', 'active')->value('device_id');

        // Empty string from the query string must not override the configured total meter.
        $fieldId = $request->query('field_id') ?: config('app.water_consumption.total');
        $fromDateTime = Carbon::parse($startDate)->startOfDay();
        $toDateTime = Carbon::parse($endDate)->endOfDay();

        $rawDailyTotals = DB::table('pulse_summary_water_meter')
            ->where('team_id', $currentTeamId)
            ->where('field_id', $fieldId)
            ->whereBetween('date', [$startDate, $endDate])
            ->pluck('litres_consumed', 'date');

        $liveDay = IotLiveDay::today();
        $yesterday = Carbon::yesterday()->format('Y-m-d');
        $liveTotal = null;

        if ($deviceId) {
            $rawDatesToCheck = array_filter([$liveDay, $yesterday], fn ($d) => IotLiveDay::isInRange($d, $startDate, $endDate));

            foreach ($rawDatesToCheck as $checkDate) {
                $summaryVal = (int) ($rawDailyTotals[$checkDate] ?? 0);
                if ($summaryVal === 0) {
                    $raw = DB::table('pulse_device_data_water_meters')
                        ->where('device_id', $deviceId)
                        ->where('field_id', $fieldId)
                        ->where('meter_date', $checkDate)
                        ->where('is_duplicate', 0)
                        ->select(
                            DB::raw('MAX(litres_counter) - MIN(litres_counter) AS raw_total'),
                            DB::raw('COUNT(id) as cnt')
                        )
                        ->first();

                    $count = (int) ($raw->raw_total ?? 0);
                    $hasRows = (int) ($raw->cnt ?? 0) > 0;
                    if ($hasRows && $count > 0) {
                        $rawDailyTotals->put($checkDate, $count);
                        if ($checkDate === $liveDay) {
                            $liveTotal = $count;
                        }
                    }
                } elseif ($checkDate === $liveDay) {
                    $liveTotal = $summaryVal;
                }
            }
        }

        $dailyLimit = config('app.water_consumption.daily_consumption_limit');
        $density = null;
        $totalSum = 0;
        $dayTotals = [];

        $date = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);

        while ($date->lte($end)) {
            $day = $date->toDateString();
            if ($day > $liveDay) {
                $date->addDay();

                continue;
            }
            $actualCount = ($day === $liveDay && $liveTotal !== null)
                ? $liveTotal
                : (int) ($rawDailyTotals[$day] ?? 0);

            $wasReset = $actualCount > $dailyLimit;
            $total = $wasReset ? 0 : $actualCount;
            $totalSum += $total;
            $dayTotals[$day] = ['total' => $total, 'wasReset' => $wasReset];

            $date->addDay();
        }

        $weekPeak = max(1, ...(array_values(array_map(fn ($d) => $d['total'], $dayTotals)) ?: [1]));

        if ($request->query('mode') === 'density') {
            [$events, $density] = $this->bucketedEvents(
                $deviceId,
                $fieldId,
                $fromDateTime,
                $toDateTime,
                $this->resolveBucketSeconds($request->query('bucket'))
            );
        } else {
            $data = DB::table('pulse_device_data_water_meters')
                ->select('litres_counter', 'meter_datetime')
                ->where('device_id', $deviceId)
                ->where('field_id', $fieldId)
                ->where('is_duplicate', 0)
                ->whereBetween('meter_date', [$startDate, $endDate])
                ->get();

            $events = $data->map(fn ($event) => [
                'start' => $event->meter_datetime,
                'end' => $event->meter_datetime,
                'classNames' => 'ec-event-height ec-event-water',
                'extendedProps' => [
                    'litres' => (int) ($event->litres_counter ?? 0),
                ],
            ]);
        }

        foreach ($dayTotals as $day => $info) {
            $resetMark = $info['wasReset'] ? "<span class='ad-total-reset' title='Counter reset occurred'>&#x21BB;</span>" : '';
            $total = NumberFormatterService::formatToIndianFormat($info['total'], false, 0);

            $events = $events instanceof Collection ? $events : collect($events);
            $events->push([
                'start' => $day,
                'end' => $day,
                'allDay' => true,
                'title' => "<span class='ad-total' title='Water Consumed'>"
                    ."<span class='ad-total-value'>{$total}</span>"
                    ."<span class='ad-total-unit'>litres</span>"
                    .$resetMark
                    .'</span>',
                'classNames' => 'ec-all-day-total',
            ]);
        }

        return response()->json([
            'events' => $events,
            'totals' => [
                'Litres' => $totalSum,
            ],
            'density' => $density,
            'today' => $liveDay,
            'liveDay' => $liveDay,
        ]);
    }

    private function resolveBucketSeconds(mixed $requested): int
    {
        $bucket = (int) $requested;

        return in_array($bucket, self::BUCKET_SECONDS, true) ? $bucket : 1800;
    }

    /**
     * @return array{0: Collection<int, array<string, mixed>>, 1: array<string, mixed>}
     */
    private function bucketedEvents($deviceId, $fieldId, Carbon $from, Carbon $to, int $bucket): array
    {
        $origin = $from->format('Y-m-d H:i:s');

        $rawBuckets = DB::table('pulse_device_data_water_meters')
            ->where('device_id', $deviceId)
            ->where('field_id', $fieldId)
            ->where('is_duplicate', 0)
            ->whereBetween('meter_datetime', [$from, $to])
            ->selectRaw(
                "FLOOR(TIMESTAMPDIFF(SECOND, '{$origin}', meter_datetime) / {$bucket}) as bucket_index,
                 MIN(litres_counter) as min_litres,
                 MAX(litres_counter) as max_litres"
            )
            ->groupBy('bucket_index')
            ->orderBy('bucket_index')
            ->get();

        $prevMax = null;
        $buckets = $rawBuckets->map(function ($row) use (&$prevMax) {
            $max = (int) $row->max_litres;
            $litres = $prevMax !== null && $max >= $prevMax ? $max - $prevMax : max(0, $max - (int) $row->min_litres);
            $prevMax = $max;

            return ['index' => (int) $row->bucket_index, 'litres' => $litres];
        })->filter(fn (array $bucketRow) => $bucketRow['litres'] > 0)->values();

        $sorted = $buckets->pluck('litres')->sort()->values();
        $peak = (int) $sorted->last() ?: 1;
        $scale = max(1, (int) $sorted->get((int) floor(($sorted->count() - 1) * 0.95)));
        $base = config('colors.water.total');

        $events = $buckets->map(function (array $bucketRow) use ($from, $bucket, $scale, $base) {
            $start = $from->copy()->addSeconds($bucketRow['index'] * $bucket);

            return [
                'start' => $start->format('Y-m-d H:i:s'),
                'end' => $start->copy()->addSeconds($bucket)->format('Y-m-d H:i:s'),
                'backgroundColor' => $this->rgba($base, self::DENSITY_TRACK_ALPHA),
                'textColor' => '#1e3a5f',
                'classNames' => 'ec-bucket',
                'extendedProps' => [
                    'litres' => $bucketRow['litres'],
                    'pct' => min(100, round($bucketRow['litres'] / $scale * 100, 1)),
                    'clamped' => $bucketRow['litres'] > $scale,
                ],
            ];
        });

        return [$events, [
            'peak' => $peak,
            'scale' => $scale,
            'bucketSeconds' => $bucket,
            'buckets' => $buckets->count(),
            'fill' => $this->rgba($base, 1.0),
            'track' => $this->rgba($base, self::DENSITY_TRACK_ALPHA),
            'unit' => 'L',
        ]];
    }

    private function rgba(?string $hex, float $alpha): string
    {
        $parsed = sscanf(ltrim((string) $hex, '#'), '%2x%2x%2x');
        [$r, $g, $b] = is_array($parsed) && ($parsed[2] ?? null) !== null
            ? $parsed
            : [29, 78, 216];

        return sprintf('rgba(%d, %d, %d, %.2f)', $r, $g, $b, $alpha);
    }
}
