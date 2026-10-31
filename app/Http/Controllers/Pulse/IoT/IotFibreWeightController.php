<?php

namespace App\Http\Controllers\Pulse\IoT;

use App\Http\Controllers\Controller;
use App\Models\Pulse\Device;
use App\Models\Pulse\Shift;
use App\Services\NumberFormatterService;
use App\Services\Pulse\FibreShiftDayTotals;
use App\Support\Pulse\IotLiveDay;
use App\Traits\AttributesReadingsToShifts;
use App\Traits\ModuleRedirectTrait;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class IotFibreWeightController extends Controller
{
    use AttributesReadingsToShifts, ModuleRedirectTrait;

    /**
     * Bucket widths the density view offers, mirroring the calendar's slot-duration
     * buttons. Every one divides 24h, which is what keeps a bucket edge on a slot edge.
     *
     * @var list<int>
     */
    private const BUCKET_SECONDS = [1800, 3600, 7200];

    /**
     * How far across a shift row the coloured band may reach, as a percentage of the
     * row's width. The figures render right-aligned in the same row, so the band stops
     * short of the full width instead of running underneath them.
     */
    private const BAR_MAX_WIDTH = 58;

    public function __construct(private readonly FibreShiftDayTotals $shiftDayTotals) {}

    public function index(Request $request)
    {
        $team = Auth::user()->currentTeam;
        if ($redirect = $this->handleMissingModules('has_fibre_module_weight')) {
            return $redirect;
        }

        [$deviceId, $shiftStartTime] = $this->getDeviceAndShiftStartTime($team->id);
        if (! $deviceId) {
            abort(404, 'No active device configured for fibre module.');
        }

        $today = now()->toDateString();

        // The column the Live badge belongs on: the shift day in progress, which before
        // the anchor time is still yesterday's date.
        $liveDay = IotLiveDay::forAnchor($shiftStartTime);

        $alertThreshold = DB::table('pulse_settings')
            ->where('team_id', $team->id)
            ->where('key', 'ALERT_THRESHOLD_LESS_WEIGHT')
            ->value('value');

        $alertThreshold = intval($alertThreshold ?? 120); // fallback to 120 if missing
        $colors = [
            'shifts' => config('colors.shift'),
            'totals' => config('colors.iot_totals.total'),
            'weights' => config('colors.weights'),
        ];

        return view('pulse::iot-insights.fibre.weekly_bales_weight', array_merge(
            compact('shiftStartTime', 'today', 'liveDay', 'alertThreshold', 'colors'),
            ['teamId' => Auth::user()->currentTeam->id]
        ));
    }

    public function fetchEventsByDate(Request $request)
    {
        $team = Auth::user()->currentTeam;
        [$deviceId, $shiftStartTime] = $this->getDeviceAndShiftStartTime($team->id);

        if (! $deviceId) {
            return response()->json(['events' => [], 'totals' => []]);
        }

        $startDate = Carbon::parse($request->query('start'))->toDateString();
        $endDate = Carbon::parse($request->query('end'))->toDateString();

        [$fromDateTime, $toDateTime] = $this->getShiftDateTimes($startDate, $endDate, $shiftStartTime);

        // Fetch threshold from settings
        $alertThreshold = DB::table('pulse_settings')
            ->where('team_id', $team->id)
            ->where('key', 'ALERT_THRESHOLD_LESS_WEIGHT')
            ->value('value');

        $alertThreshold = intval($alertThreshold ?? 120); // fallback to 120 if missing

        $liveDay = IotLiveDay::forAnchor($shiftStartTime);

        // Daily shift-wise totals
        $shiftWiseTotals = $this->calcShiftWiseDayTotals($team->id, $startDate, $endDate);

        // Top summary, folded up from the day buckets
        $totals = $this->calcShiftTotals($shiftWiseTotals);

        // Assign shift sequence numbers
        $shift_nos = collect($shiftWiseTotals)
            ->pluck('shift_id')
            ->unique()
            ->values()
            ->mapWithKeys(fn ($id, $index) => [$id => $index + 1])
            ->toArray();

        $density = null;

        if ($request->query('mode') === 'density') {
            [$events, $density] = $this->bucketedWeightEvents(
                $deviceId,
                $fromDateTime,
                $toDateTime,
                $this->resolveBucketSeconds($request->query('bucket')),
                $alertThreshold
            );
        } else {
            // Fetch raw bale events
            $eventsRaw = DB::table('pulse_device_data_fibre_mega_bales_weight')
                ->select('shift_id', 'bale_weight_datetime', 'bale_weight')
                ->where('device_id', $deviceId)
                ->whereBetween('bale_weight_datetime', [$fromDateTime, $toDateTime])
                ->get();

            // Map events to calendar format
            $events = $eventsRaw->map(function ($event) use ($alertThreshold) {
                return [
                    'start' => $event->bale_weight_datetime,
                    'end' => $event->bale_weight_datetime,
                    'classNames' => 'ec-event-height',
                    'backgroundColor' => $this->getWeightColor($event->bale_weight, $alertThreshold),
                    'extendedProps' => [
                        'weight' => $event->bale_weight,
                    ],
                ];
            });
        }

        // Add all-day blocks
        $events = $this->appendShiftBlocksAndDailyTotals($events, $shiftWiseTotals, $shift_nos, $liveDay, $startDate, $endDate);

        return response()->json([
            'events' => $events,
            'totals' => $totals,
            'density' => $density,
            'today' => IotLiveDay::today(),
            'liveDay' => $liveDay,
        ]);
    }

    private function resolveBucketSeconds(mixed $requested): int
    {
        $bucket = (int) $requested;

        return in_array($bucket, self::BUCKET_SECONDS, true) ? $bucket : 1800;
    }

    /**
     * SQL counting weighings into the three bands the Weight Range legend names.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function weightBandSql(int $threshold): array
    {
        $high = $threshold + 20;

        return [
            "SUM(bale_weight < {$threshold})",
            "SUM(bale_weight >= {$threshold} AND bale_weight <= {$high})",
            "SUM(bale_weight > {$high})",
        ];
    }

    /**
     * Time-grid marks as one block per fixed-width time bucket instead of one 4px sliver
     * per weighing, the bar's length carrying how many bales were weighed and its segments
     * how they split across the weight bands.
     *
     * Buckets are measured from $from — the grid's own origin — instead of the Unix epoch,
     * so a bucket edge lands on a slot edge on every day of the week.
     *
     * @return array{0: Collection<int, array<string, mixed>>, 1: array<string, mixed>}
     */
    private function bucketedWeightEvents($deviceId, Carbon $from, Carbon $to, int $bucket, int $threshold): array
    {
        $origin = $from->format('Y-m-d H:i:s');
        [$low, $normal, $high] = $this->weightBandSql($threshold);

        $buckets = DB::table('pulse_device_data_fibre_mega_bales_weight')
            ->where('device_id', $deviceId)
            ->whereBetween('bale_weight_datetime', [$from, $to])
            ->selectRaw(
                "FLOOR(TIMESTAMPDIFF(SECOND, '{$origin}', bale_weight_datetime) / {$bucket}) as bucket_index,
                 COUNT(*) as bales,
                 COALESCE(SUM(bale_weight), 0) as weight,
                 {$low} as low,
                 {$normal} as normal,
                 {$high} as high"
            )
            ->groupBy('bucket_index')
            ->orderBy('bucket_index')
            ->get()
            ->map(fn ($row) => [
                'index' => (int) $row->bucket_index,
                'bales' => (int) $row->bales,
                'weight' => (float) $row->weight,
                'low' => (int) $row->low,
                'normal' => (int) $row->normal,
                'high' => (int) $row->high,
            ])
            ->filter(fn (array $bucketRow) => $bucketRow['bales'] > 0)
            ->values();

        $sorted = $buckets->pluck('bales')->sort()->values();
        $peak = (int) $sorted->last() ?: 1;

        // A full bar is the 95th percentile, not the peak, so a handful of outlier buckets
        // cannot squash a typical one; the few above the scale clamp and are flagged.
        $scale = max(1, (int) $sorted->get((int) floor(($sorted->count() - 1) * 0.95)));

        $events = $buckets->map(function (array $bucketRow) use ($from, $bucket, $scale) {
            $start = $from->copy()->addSeconds($bucketRow['index'] * $bucket);

            return [
                'start' => $start->format('Y-m-d H:i:s'),
                'end' => $start->copy()->addSeconds($bucket)->format('Y-m-d H:i:s'),
                'backgroundColor' => 'rgba(107, 114, 128, 0.10)',
                'classNames' => 'ec-wbucket',
                'extendedProps' => [
                    'bales' => $bucketRow['bales'],
                    'pct' => min(100, round($bucketRow['bales'] / $scale * 100, 1)),
                    'clamped' => $bucketRow['bales'] > $scale,
                    'low' => $bucketRow['low'],
                    'normal' => $bucketRow['normal'],
                    'high' => $bucketRow['high'],
                    'avg' => round($bucketRow['weight'] / max(1, $bucketRow['bales'])),
                ],
            ];
        });

        return [$events, [
            'peak' => $peak,
            'scale' => $scale,
            'bucketSeconds' => $bucket,
            'threshold' => $threshold,
            'colors' => config('colors.weights'),
        ]];
    }

    // Get deviceId & shift start time for team
    private function getDeviceAndShiftStartTime(int $teamId): array
    {
        $deviceId = Device::where('team_id', $teamId)
            ->whereIn('module', ['fibre', 'baling'])
            ->where('status', 'active')
            ->value('device_id');

        $shiftStartTime = Shift::anchorForTeam($teamId);

        return [$deviceId, $shiftStartTime];
    }

    /**
     * Top weekly summary, folded up from the very rows that draw the calendar chips so the
     * strip and the calendar can never disagree.
     *
     * @param  Collection<int, object>  $shiftWiseTotals
     * @return array<string, array{total_bales_weighed: int, total_weight: float}>
     */
    private function calcShiftTotals(Collection $shiftWiseTotals): array
    {
        return $shiftWiseTotals->reduce(function ($carry, $row) {
            $carry[$row->shift_name] ??= ['total_bales_weighed' => 0, 'total_weight' => 0.0];
            $carry[$row->shift_name]['total_bales_weighed'] += (int) $row->total_bales_weighed;
            $carry[$row->shift_name]['total_weight'] += (float) $row->total_weight;

            return $carry;
        }, []);
    }

    /**
     * The week's shift figures, from the one resolver every fibre screen reads, projected
     * onto the field names this calendar's cards and summary strip expect.
     */
    private function calcShiftWiseDayTotals(int $teamId, string $startDate, string $endDate): Collection
    {
        return $this->shiftDayTotals->forRange($teamId, $startDate, $endDate)
            ->map(fn (object $row) => (object) [
                'shift_date' => $row->shift_date,
                'shift_id' => $row->shift_id,
                'shift_name' => $row->shift_name,
                'total_bales_weighed' => $row->bales_weighed,
                'total_weight' => $row->weight,
            ])
            ->reject(fn (object $row) => $row->total_bales_weighed <= 0)
            ->values();
    }

    /**
     * One all-day card per day: the day's total, then one row per shift, each row a bar
     * whose length is that shift's count on the week's scale, then the average weight.
     *
     * The day is a single event rather than one event per shift so that every row in a
     * column shares the card's width — the bars then start on one left edge and the
     * figures end on one right edge, in each of the seven columns.
     *
     * Average weight is the last figure and the faintest one on purpose: it barely moves
     * from the machine's spec across a week, so as an equal third column it would spend
     * the cell's width restating a constant. It reads as a spec check under the shift
     * lines instead.
     */
    private function appendShiftBlocksAndDailyTotals($events, $shiftWiseTotals, $classMap, string $liveDay, string $startDate, string $endDate)
    {
        $inRange = collect($shiftWiseTotals)->reject(fn ($row) => $row->shift_date > $liveDay);
        $byDate = $inRange->groupBy('shift_date');

        // Every shift that weighed this week keeps its own line in all seven columns, so
        // a day where only Shift 2 weighed leaves Shift 1's line empty rather than
        // sliding Shift 2 up into it — the same shift then sits on the same line in
        // every column and the cards stay comparable row for row.
        $sequences = $inRange->map(fn ($row) => $classMap[$row->shift_id] ?? 0)
            ->unique()->sort()->values()->all();

        // One scale for the whole week, taken from its busiest shift: the bars are read
        // across the columns as much as down one, so a day cannot be allowed to
        // normalise against itself and draw its best shift full-length.
        $peak = (float) $inRange->max(fn ($row) => (int) $row->total_bales_weighed);

        $cursor = Carbon::parse($startDate);
        $last = Carbon::parse($endDate);

        for (; $cursor->lessThanOrEqualTo($last); $cursor->addDay()) {
            $date = $cursor->toDateString();
            if ($date > $liveDay) {
                break;
            }

            $events->push([
                'title' => $this->buildDayCard($byDate->get($date) ?? collect(), $classMap, $sequences, $peak),
                'start' => $date,
                'end' => $date,
                'allDay' => true,
                'classNames' => 'ec-all-day-custom-total',
            ]);
        }

        return $events;
    }

    /**
     * The rows of one day's card.
     *
     * @param  Collection<int, object>  $rows  one row per shift that weighed that day
     * @param  array<int, int>  $classMap  shift id => sequence number, which picks its colour
     * @param  list<int>  $sequences  shift sequences to reserve a line for, in order
     * @param  float  $peak  the week's largest shift count, which a full-length bar is worth
     */
    private function buildDayCard(Collection $rows, array $classMap, array $sequences, float $peak): string
    {
        $palette = config('colors.shift');
        $fallback = config('colors.fibre.total');

        $totalWeighed = (int) $rows->sum(fn ($row) => (int) $row->total_bales_weighed);
        $totalWeight = (float) $rows->sum(fn ($row) => (float) $row->total_weight);

        // An empty day says so once, in place of the headline; the row keeps its height
        // from .fw-row-head so the shift lines below stay level across the week.
        $headline = $rows->isEmpty()
            ? "<span class='fw-chip'>nothing weighed</span>"
            : "<span class='fw-big'>".$this->formatCount($totalWeighed)."<span class='fw-unit'>bales</span></span>";

        $tons = $totalWeighed > 0
            ? "<span class='fw-total-tons'>".$this->formatTons($totalWeight).'</span>'
            : '';

        $bySequence = [];
        foreach ($rows as $row) {
            $bySequence[$classMap[$row->shift_id] ?? 0][] = $row;
        }

        $lines = '';
        foreach ($sequences as $sequence) {
            $inSlot = $bySequence[$sequence] ?? [];
            foreach ($inSlot as $row) {
                $lines .= $this->buildShiftRow($row, $palette[$sequence] ?? $fallback, $peak);
            }
            if ($inSlot === []) {
                $lines .= "<span class='fw-sline fw-sline-empty'></span>";
            }
        }

        $average = '';
        if ($totalWeighed > 0) {
            $averageKg = number_format($totalWeight / $totalWeighed, 1);
            $average = "<span class='fw-avg'>{$averageKg} kg / bale</span>";
        }

        return "<div class='fw-card'>"
            ."<div class='fw-row fw-row-head'>{$headline}{$tons}</div>"
            .$lines
            .$average
            .'</div>';
    }

    /**
     * One shift on one line: the row itself is the chart. A band runs to that shift's
     * share of the week's peak and a rule marks where it ends, with the count and its
     * tonnage right-aligned beside it.
     */
    private function buildShiftRow(object $row, string $color, float $peak): string
    {
        $weighed = (int) $row->total_bales_weighed;
        $weight = (float) $row->total_weight;
        $label = e($this->shortShiftLabel((string) $row->shift_name));
        $title = e("{$row->shift_name}: ".$this->formatCount($weighed).' bales · '.$this->formatTons($weight));

        $bar = '';
        if ($peak > 0 && $weighed > 0) {
            $width = round(min($weighed / $peak, 1) * self::BAR_MAX_WIDTH, 2);
            $bar = "<span class='fw-band' style='width:{$width}%; background:{$color};'></span>"
                ."<span class='fw-edge' style='left:{$width}%; background:{$color};'></span>";
        }

        return "<span class='fw-sline' title='{$title}'>"
            .$bar
            ."<span class='fw-stext'>"
            ."<span class='fw-lbl' style='color:{$color};'>{$label}</span>"
            ."<span class='fw-nums'><b>".$this->formatCount($weighed).'</b>'
            ." · <span class='fw-muted'>".$this->formatTons($weight).'</span>'
            .'</span></span></span>';
    }

    /**
     * "Shift 1" -> "S1" so the label fits on one line beside its figures. Names that
     * don't follow that convention keep their first few characters.
     */
    private function shortShiftLabel(string $name): string
    {
        if (preg_match('/^shift\s*(\S+)$/i', trim($name), $matches) === 1) {
            return 'S'.$matches[1];
        }

        return mb_substr($name, 0, 4);
    }

    private function formatCount(int $balesWeighed): string
    {
        return NumberFormatterService::formatToIndianFormat($balesWeighed, false, 0);
    }

    /**
     * Tonnage to one decimal: a shift moves tens of tonnes, so the tenth is the finest
     * digit that separates one day from another, and two would only add noise.
     *
     * number_format, not the Indian formatter, because the decimal has to be printed
     * even when it is a zero — the formatter drops it, and "34 T" above "36.4 T" breaks
     * the decimal point the column is read down.
     */
    private function formatTons(float $weight): string
    {
        return number_format($weight / 1000, 1).' T';
    }

    // Calculate date ranges based on shift start time
    private function getShiftDateTimes(string $startDate, string $endDate, string $shiftStartTime)
    {
        if ($shiftStartTime === '00:00:00') {
            return [Carbon::parse($startDate)->startOfDay(), Carbon::parse($endDate)->endOfDay()];
        }

        return [
            Carbon::parse($startDate)->setTimeFromTimeString($shiftStartTime),
            Carbon::parse($endDate)->addDay()->setTimeFromTimeString($shiftStartTime)->subSecond(),
        ];
    }

    private function getWeightColor($weight, $alertThreshold)
    {
        $colors = config('colors.weights');
        $high = $alertThreshold + 20;

        if ($weight < $alertThreshold) {
            return $colors['low'];
        } elseif ($weight <= $high) {
            return $colors['normal'];
        } else {
            return $colors['high'];
        }
    }
}
