<?php

namespace App\Http\Controllers\Pulse\IoT;

use App\Http\Controllers\Controller;
use App\Models\Pulse\Device;
use App\Models\Pulse\Shift;
use App\Services\NumberFormatterService;
use App\Support\Pulse\IotLiveDay;
use App\Traits\ModuleRedirectTrait;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class IotPithBlocksController extends Controller
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
        if ($redirect = $this->handleMissingModules('has_pith_module_blocks')) {
            return $redirect;
        }

        $today = now()->toDateString();
        $currentTeamId = $team->id;
        $shiftStartTime = Shift::anchorForTeam($currentTeamId);
        $liveDay = IotLiveDay::forAnchor($shiftStartTime);
        $pithColors = config('colors.pith');
        $colors = [
            'shifts' => config('colors.shift'),
            'totals' => config('colors.iot_totals.total'),
        ];

        return view('pulse::iot-insights.pith.weekly_blocks', array_merge(
            compact('today', 'pithColors', 'shiftStartTime', 'liveDay', 'colors'),
            ['teamId' => $currentTeamId]
        ));
    }

    public function fetchEventsByDate(Request $request)
    {
        $currentTeamId = Auth::user()->currentTeam->id;

        $deviceId = Device::where('team_id', $currentTeamId)
            ->whereIn('module', ['blocks', 'pith'])
            ->where('status', 'active')
            ->value('device_id')
            ?: Device::where('team_id', $currentTeamId)
                ->where('status', 'active')
                ->value('device_id');

        $shiftStartTime = Shift::anchorForTeam($currentTeamId);
        $liveDay = IotLiveDay::forAnchor($shiftStartTime);

        $startDate = Carbon::parse($request->query('start'))->format('Y-m-d');
        $endDate = Carbon::parse($request->query('end'))->format('Y-m-d');

        [$fromDateTime, $toDateTime] = $this->getShiftDateTimes($startDate, $endDate, $shiftStartTime);

        $dayTotals = $this->calcDayTotals(
            $deviceId,
            $currentTeamId,
            $shiftStartTime,
            $fromDateTime,
            $toDateTime,
            $startDate,
            $endDate
        );

        $totals = $this->calcPeriodTotals($dayTotals);

        $density = null;

        if ($request->query('mode') === 'density') {
            [$events, $density] = $this->bucketedEvents(
                $deviceId,
                $fromDateTime,
                $toDateTime,
                $this->resolveBucketSeconds($request->query('bucket'))
            );
        } else {
            $eventsRaw = DB::table('pulse_device_data_pith_blocks')
                ->select('block_datetime')
                ->where('device_id', $deviceId)
                ->where('is_duplicate', 0)
                ->whereBetween('block_datetime', [$fromDateTime, $toDateTime])
                ->get();

            $events = $eventsRaw->map(fn ($event) => [
                'start' => $event->block_datetime,
                'end' => $event->block_datetime,
                'classNames' => 'ec-event-height ec-event-blocks',
                'extendedProps' => [
                    'blocks' => 1,
                ],
            ]);
        }

        $events = $this->appendDailyTotals($events, $dayTotals, $liveDay);

        return response()->json([
            'events' => $events,
            'totals' => $totals,
            'density' => $density,
            'today' => IotLiveDay::today(),
            'liveDay' => $liveDay,
            'colors' => [
                'shifts' => config('colors.shift'),
            ],
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
    private function bucketedEvents($deviceId, Carbon $from, Carbon $to, int $bucket): array
    {
        $origin = $from->format('Y-m-d H:i:s');

        $rawBuckets = DB::table('pulse_device_data_pith_blocks')
            ->where('device_id', $deviceId)
            ->where('is_duplicate', 0)
            ->whereBetween('block_datetime', [$from, $to])
            ->selectRaw(
                "FLOOR(TIMESTAMPDIFF(SECOND, '{$origin}', block_datetime) / {$bucket}) as bucket_index,
                 MIN(blocks_counter) as min_c,
                 MAX(blocks_counter) as max_c"
            )
            ->groupBy('bucket_index')
            ->orderBy('bucket_index')
            ->get();

        $prevMax = null;
        $buckets = $rawBuckets->map(function ($row) use (&$prevMax) {
            $max = (int) $row->max_c;
            $blocks = $prevMax !== null && $max >= $prevMax ? $max - $prevMax : max(0, ($max - (int) $row->min_c) + 1);
            $prevMax = $max;

            return ['index' => (int) $row->bucket_index, 'blocks' => $blocks];
        })->filter(fn (array $bucketRow) => $bucketRow['blocks'] > 0)->values();

        $sorted = $buckets->pluck('blocks')->sort()->values();
        $peak = (int) $sorted->last() ?: 1;
        $scale = max(1, (int) $sorted->get((int) floor(($sorted->count() - 1) * 0.95)));
        $base = config('colors.pith.total');

        $events = $buckets->map(function (array $bucketRow) use ($from, $bucket, $scale, $base) {
            $start = $from->copy()->addSeconds($bucketRow['index'] * $bucket);

            return [
                'start' => $start->format('Y-m-d H:i:s'),
                'end' => $start->copy()->addSeconds($bucket)->format('Y-m-d H:i:s'),
                'backgroundColor' => $this->rgba($base, self::DENSITY_TRACK_ALPHA),
                'textColor' => '#4b3011',
                'classNames' => 'ec-bucket',
                'extendedProps' => [
                    'blocks' => $bucketRow['blocks'],
                    'pct' => min(100, round($bucketRow['blocks'] / $scale * 100, 1)),
                    'clamped' => $bucketRow['blocks'] > $scale,
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
            'unit' => 'blocks',
        ]];
    }

    private function rgba(?string $hex, float $alpha): string
    {
        $parsed = sscanf(ltrim((string) $hex, '#'), '%2x%2x%2x');
        [$r, $g, $b] = is_array($parsed) && ($parsed[2] ?? null) !== null
            ? $parsed
            : [206, 119, 22];

        return sprintf('rgba(%d, %d, %d, %.2f)', $r, $g, $b, $alpha);
    }

    /**
     * @param  Collection<int, object>  $dayTotals
     * @return array<string, array{blocks_expected: int, blocks_produced: int}>
     */
    private function calcPeriodTotals(Collection $dayTotals): array
    {
        if ($dayTotals->isEmpty()) {
            return [];
        }

        return [
            'Blocks' => [
                'blocks_expected' => (int) $dayTotals->sum(fn ($row) => (int) $row->blocks_expected),
                'blocks_produced' => (int) $dayTotals->sum(fn ($row) => (int) $row->blocks_produced),
            ],
        ];
    }

    private function calcDayTotals(
        $deviceId,
        int $teamId,
        string $shiftStart,
        Carbon $from,
        Carbon $to,
        string $startDate,
        string $endDate
    ): Collection {
        $normal = DB::table('pulse_summary_pith_blocks')
            ->where('team_id', $teamId)
            ->whereBetween('shift_date', [$startDate, $endDate])
            ->groupBy('shift_date')
            ->orderBy('shift_date')
            ->select(
                'shift_date',
                DB::raw('SUM(COALESCE(blocks_produced, 0)) as blocks_produced'),
                DB::raw('SUM(COALESCE(blocks_expected, 0)) as blocks_expected'),
                DB::raw('SUM(CASE WHEN COALESCE(blocks_expected, 0) > 0 THEN blocks_expected ELSE COALESCE(blocks_produced, 0) END) as total_blocks')
            )->get();

        if ($deviceId) {
            $telemetryTotals = DB::table('pulse_device_data_pith_blocks')
                ->where('device_id', $deviceId)
                ->where('is_duplicate', 0)
                ->whereBetween('block_datetime', [$from, $to])
                ->selectRaw('DATE(DATE_SUB(block_datetime, INTERVAL TIME_TO_SEC(?) SECOND)) as shift_date, (MAX(blocks_counter) - MIN(blocks_counter) + 1) as expected', [$shiftStart])
                ->groupBy('shift_date')
                ->pluck('expected', 'shift_date');

            $normal->each(function ($row) use ($telemetryTotals) {
                if ($exp = (int) ($telemetryTotals[$row->shift_date] ?? 0)) {
                    $row->blocks_expected = $row->total_blocks = $exp;
                }
            });
        }

        $liveDay = IotLiveDay::forAnchor($shiftStart);
        $hasLiveSummary = $normal->contains(
            fn ($item) => $item->shift_date === $liveDay && (int) $item->total_blocks > 0
        );

        if (! $hasLiveSummary) {
            $liveRows = $this->liveDayRows($deviceId, $shiftStart, $startDate, $endDate);
            if ($liveRows->isNotEmpty()) {
                $normal = $normal->reject(fn ($item) => $item->shift_date === $liveDay)->merge($liveRows);
            }
        }

        $daysWithTotals = $normal
            ->groupBy('shift_date')
            ->filter(fn (Collection $rows) => $rows->sum(fn ($row) => (int) $row->total_blocks) > 0)
            ->keys();

        $historical = $this->historicalDayRows(
            $deviceId,
            $shiftStart,
            $from,
            $to,
            $startDate,
            $endDate,
            $liveDay,
            $daysWithTotals
        );

        return $normal->merge($historical)
            ->reject(fn ($row) => $row->shift_date > $liveDay)
            ->filter(fn ($row) => (int) $row->total_blocks > 0)
            ->groupBy(fn ($row) => $row->shift_date)
            ->map(fn (Collection $rows) => (object) [
                'shift_date' => $rows->first()->shift_date,
                'total_blocks' => $rows->sum(fn ($row) => (int) $row->total_blocks),
                'blocks_expected' => $rows->sum(fn ($row) => (int) ($row->blocks_expected ?? 0)),
                'blocks_produced' => $rows->sum(fn ($row) => (int) ($row->blocks_produced ?? 0)),
            ])
            ->sortBy('shift_date')
            ->values();
    }

    /**
     * Full-day totals from raw readings when summary coverage is missing.
     *
     * @param  Collection<int, string>  $daysWithTotals
     * @return Collection<int, object>
     */
    private function historicalDayRows(
        $deviceId,
        string $shiftStart,
        Carbon $from,
        Carbon $to,
        string $startDate,
        string $endDate,
        string $liveDay,
        Collection $daysWithTotals
    ): Collection {
        if (! $deviceId) {
            return collect();
        }

        $rows = collect();
        $cursor = Carbon::parse($startDate);
        $lastHistorical = Carbon::parse($liveDay)->subDay();
        $rangeEnd = Carbon::parse($endDate);
        if ($lastHistorical->lt($cursor) || $rangeEnd->lt($cursor)) {
            return collect();
        }
        if ($rangeEnd->lt($lastHistorical)) {
            $lastHistorical = $rangeEnd->copy();
        }

        while ($cursor->lte($lastHistorical)) {
            $day = $cursor->toDateString();
            $cursor->addDay();

            if ($daysWithTotals->contains($day)) {
                continue;
            }

            [$dayStart, $dayEnd] = $this->getShiftDateTimes($day, $day, $shiftStart);
            if ($dayEnd->lt($from) || $dayStart->gt($to)) {
                continue;
            }

            $reading = DB::table('pulse_device_data_pith_blocks')
                ->where('device_id', $deviceId)
                ->where('is_duplicate', 0)
                ->whereBetween('block_datetime', [$dayStart, $dayEnd])
                ->selectRaw('(MAX(blocks_counter) - MIN(blocks_counter)) as raw_diff, COUNT(id) as cnt')
                ->first();

            $blocks = IotLiveDay::counterSpan($reading->raw_diff ?? 0, (int) ($reading->cnt ?? 0));
            if ($blocks <= 0) {
                continue;
            }

            $rows->push((object) [
                'shift_date' => $day,
                'total_blocks' => $blocks,
                'blocks_expected' => $blocks,
                'blocks_produced' => 0,
            ]);
        }

        return $rows->values();
    }

    /**
     * @return Collection<int, object>
     */
    private function liveDayRows($deviceId, string $shiftStart, string $startDate, string $endDate): Collection
    {
        $liveDay = IotLiveDay::forAnchor($shiftStart);
        if (! $deviceId || ! IotLiveDay::isInRange($liveDay, $startDate, $endDate)) {
            return collect();
        }

        $now = Carbon::now();
        [$start] = $this->getShiftDateTimes($liveDay, $liveDay, $shiftStart);
        if ($start->greaterThan($now)) {
            return collect();
        }

        $end = $now->copy();

        $reading = DB::table('pulse_device_data_pith_blocks')
            ->where('device_id', $deviceId)
            ->where('is_duplicate', 0)
            ->whereBetween('block_datetime', [$start, $end])
            ->selectRaw('(MAX(blocks_counter) - MIN(blocks_counter)) as raw_diff, COUNT(id) as cnt')
            ->first();

        $liveBlocks = IotLiveDay::counterSpan($reading->raw_diff ?? 0, (int) ($reading->cnt ?? 0));
        if ($liveBlocks <= 0) {
            return collect();
        }

        return collect([(object) [
            'shift_date' => $liveDay,
            'total_blocks' => $liveBlocks,
            'blocks_expected' => $liveBlocks,
            'blocks_produced' => 0,
        ]]);
    }

    private function appendDailyTotals($events, $dayTotals, string $liveDay)
    {
        foreach ($dayTotals as $dayTotal) {
            $date = $dayTotal->shift_date;
            if ($date > $liveDay) {
                continue;
            }

            $total = NumberFormatterService::formatToIndianFormat($dayTotal->total_blocks, false, 0);

            $events->push([
                'title' => "<span class='ad-total' title='Blocks Production'>"
                    ."<span class='ad-total-value'>{$total}</span>"
                    ."<span class='ad-total-unit'>blocks</span>"
                    .'</span>',
                'start' => $date,
                'end' => $date,
                'allDay' => true,
                'classNames' => 'ec-all-day-custom-1',
            ]);
        }

        return $events;
    }

    private function getShiftDateTimes(string $startDate, string $endDate, string $shiftStartTime): array
    {
        if ($shiftStartTime === '00:00:00') {
            return [Carbon::parse($startDate)->startOfDay(), Carbon::parse($endDate)->endOfDay()];
        }

        return [
            Carbon::parse($startDate)->setTimeFromTimeString($shiftStartTime),
            Carbon::parse($endDate)->addDay()->setTimeFromTimeString($shiftStartTime)->subSecond(),
        ];
    }
}
