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

class IotFibreController extends Controller
{
    use AttributesReadingsToShifts, ModuleRedirectTrait;

    /**
     * Bucket widths the density view offers, mirroring the calendar's slot-duration
     * buttons. Every one divides 24h, which is what keeps a bucket edge on a slot edge.
     *
     * @var list<int>
     */
    private const BUCKET_SECONDS = [60, 120, 300, 600, 900, 1800, 3600, 7200];

    /**
     * Tint of the empty part of a density bucket's bar, behind the filled portion.
     */
    private const DENSITY_TRACK_ALPHA = 0.10;

    public function __construct(private readonly FibreShiftDayTotals $shiftDayTotals) {}

    /**
     * How far across a shift row the coloured band may reach, as a percentage of the
     * row's width. The counts render right-aligned in the same row, so the band stops
     * short of the full width instead of running underneath them. Bales carry no
     * estimate to compare against, so unlike the pith card there is no second mark —
     * the band alone is the magnitude.
     */
    private const BAR_MAX_WIDTH = 58;

    public function index(Request $request)
    {
        $team = Auth::user()->currentTeam;
        if ($redirect = $this->handleMissingModules('has_fibre_module')) {
            return $redirect;
        }

        $today = now()->toDateString();
        $currentTeamId = Auth::user()->currentTeam->id;

        $shiftStartTime = Shift::anchorForTeam($currentTeamId);

        // The column the Live badge belongs on: the shift day in progress, which before
        // the anchor time is still yesterday's date.
        $liveDay = IotLiveDay::forAnchor($shiftStartTime);

        // Pull colors from config
        $colors = [
            'shifts' => config('colors.shift'),
            'totals' => config('colors.iot_totals.total'),
        ];

        // Return the view with the data
        return view('pulse::iot-insights.fibre.weekly_bales', array_merge(
            compact('shiftStartTime', 'today', 'liveDay', 'colors'),
            ['teamId' => Auth::user()->currentTeam->id]
        ));
    }

    public function fetchEventsByDate(Request $request)
    {
        $currentTeamId = Auth::user()->currentTeam->id;

        $deviceId = Device::where('team_id', $currentTeamId)
            ->where('module', 'fibre')
            ->value('device_id');

        $shiftStartTime = Shift::anchorForTeam($currentTeamId);
        $liveDay = IotLiveDay::forAnchor($shiftStartTime);

        // Get start and end date (and shift times)
        $startDate = Carbon::parse($request->query('start'))->format('Y-m-d');
        $endDate = Carbon::parse($request->query('end'))->format('Y-m-d');

        [$fromDateTime, $toDateTime] = $this->getShiftDateTimes($startDate, $endDate, $shiftStartTime);

        // shift-wise day‐buckets
        $shiftWiseTotals = $this->calcShiftWiseDayTotals($currentTeamId, $startDate, $endDate);

        // overall totals per shift, folded up from the day buckets
        $totals = $this->calcShiftTotals($shiftWiseTotals);

        // assign shift sequence as css classname
        $shift_nos = [];
        $i = 1;
        foreach ($shiftWiseTotals as $shiftTotal) {
            if (! isset($shift_nos[$shiftTotal->shift_id])) {
                $shift_nos[$shiftTotal->shift_id] = $i++;
            }
        }

        $density = null;

        if ($request->query('mode') === 'density') {
            $segments = $this->shiftDaySegments(
                Shift::activeForTeam($currentTeamId)->get(),
                $shiftStartTime
            );

            [$events, $density] = $this->bucketedEvents(
                $deviceId,
                $fromDateTime,
                $toDateTime,
                $this->resolveBucketSeconds($request->query('bucket')),
                $shiftStartTime,
                $segments,
                $this->shiftColorMap($shift_nos, $segments)
            );
        } else {
            // the raw bale events
            $eventsRaw = DB::table('pulse_device_data_fibre_bales')
                ->select('shift_id', 'bale_datetime')
                ->where('device_id', $deviceId)
                ->whereBetween('bale_datetime', [$fromDateTime, $toDateTime])
                ->get();

            $defaultShiftId = Shift::where('team_id', $currentTeamId)->where('is_active', 1)->value('id');
            // Format individual events for the calendar
            $events = $eventsRaw->map(function ($event) use ($shift_nos, $defaultShiftId) {
                $shiftId = $event->shift_id ?? $defaultShiftId;
                $class = ($shiftId && array_key_exists($shiftId, $shift_nos)) ? $shift_nos[$shiftId] : '1';

                return [
                    'start' => $event->bale_datetime,
                    'end' => $event->bale_datetime,
                    'classNames' => 'shift'.$class.'-color ec-event-height',
                    'extendedProps' => [
                        'bales' => 1,
                    ],
                ];
            });
        }

        // append per-shift-day bars & daily totals (only when >1 shift)
        $events = $this->appendShiftBlocksAndDailyTotals($events, $shiftWiseTotals, $shift_nos, $liveDay, $startDate, $endDate);

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
     * Time-grid marks as one block per fixed-width time bucket, shaded by how many bales
     * landed in it, rather than one 4px sliver per bale.
     *
     * Buckets are measured from $from — the grid's own origin — instead of the Unix epoch,
     * so a bucket edge lands on a slot edge on every day of the week.
     *
     * A bucket is worth the distinct counter values that landed in it. This device stream
     * arrives out of order — buffered readings interleave with live ones, so the counter
     * steps backwards by a few and catches up again — which makes any approach that
     * differences bucket boundaries mis-attribute bales across the edge. Distinct counters
     * are immune to that and to repeats: on a sample week they reconciled exactly with the
     * summary table's shift totals at 10m, 30m and 1h buckets, where differencing bucket
     * boundaries drifted about 1% and varied with the bucket width.
     *
     * Counting is split by shift so each bar can carry its shift's colour. Readings are
     * placed by their time of day rather than by the stored shift_id, which the nightly job
     * only stamps once the shift day is over — grouping on it would leave the whole live day
     * uncoloured.
     *
     * @param  list<array{id: int, name: string, until: int}>  $segments
     * @param  array<int, string>  $shiftColors
     * @return array{0: Collection<int, array<string, mixed>>, 1: array<string, mixed>}
     */
    private function bucketedEvents($deviceId, Carbon $from, Carbon $to, int $bucket, string $shiftStart, array $segments, array $shiftColors): array
    {
        $origin = $from->format('Y-m-d H:i:s');
        $base = config('colors.fibre.total');

        // Left-to-right order of the bands inside a bucket the boundary runs through, and
        // the order the legend lists the shifts in: chronological within the shift day.
        $order = array_flip(array_column($segments, 'id'));
        $shiftIdSql = $segments === []
            ? '0'
            : $this->shiftIdCaseSql($segments, $shiftStart, 'bale_datetime');

        $buckets = DB::table('pulse_device_data_fibre_bales')
            ->where('device_id', $deviceId)
            ->where('is_duplicate', 0)
            ->whereBetween('bale_datetime', [$from, $to])
            ->selectRaw(
                "FLOOR(TIMESTAMPDIFF(SECOND, '{$origin}', bale_datetime) / {$bucket}) as bucket_index,
                 {$shiftIdSql} as bucket_shift_id,
                 COUNT(DISTINCT bales_counter) as bales"
            )
            // Grouping on the aliases, not the raw expressions: MySQL resolves an
            // unqualified GROUP BY name against the FROM clause first, so an alias of
            // `shift_id` would silently group on the table's own (still null) column.
            ->groupBy('bucket_index', 'bucket_shift_id')
            ->get()
            ->groupBy('bucket_index')
            ->map(fn (Collection $rows, $index) => [
                'index' => (int) $index,
                'bales' => (int) $rows->sum('bales'),
                'parts' => $rows
                    ->filter(fn ($row) => (int) $row->bales > 0)
                    ->sortBy(fn ($row) => $order[(int) $row->bucket_shift_id] ?? PHP_INT_MAX)
                    ->map(fn ($row) => ['shift_id' => (int) $row->bucket_shift_id, 'bales' => (int) $row->bales])
                    ->values()
                    ->all(),
            ])
            ->filter(fn (array $bucketRow) => $bucketRow['bales'] > 0)
            ->sortBy('index')
            ->values();

        $sorted = $buckets->pluck('bales')->sort()->values();
        $peak = (int) $sorted->last() ?: 1;

        // A full bar is the 95th percentile, not the peak. Scaling to the peak lets a
        // handful of outlier buckets squash a typical one to a third of the width, which
        // hides exactly the run-to-run differences the view exists to show; the few
        // buckets above the scale clamp and are flagged instead.
        $scale = max(1, (int) $sorted->get((int) floor(($sorted->count() - 1) * 0.95)));

        // Bar length carries the magnitude, against a zero baseline: a machine holding
        // rate draws a straight edge and a dip notches it. Shading was tried first and
        // could not separate a steady band without either washing out or exaggerating a
        // 3% difference into a visible step.
        $events = $buckets->map(function (array $bucketRow) use ($from, $bucket, $scale, $base, $shiftColors) {
            $start = $from->copy()->addSeconds($bucketRow['index'] * $bucket);
            $colors = array_map(
                fn (array $part) => $shiftColors[$part['shift_id']] ?? $base,
                $bucketRow['parts']
            );

            return [
                'start' => $start->format('Y-m-d H:i:s'),
                'end' => $start->copy()->addSeconds($bucket)->format('Y-m-d H:i:s'),
                'backgroundColor' => $this->rgba($colors[0] ?? $base, self::DENSITY_TRACK_ALPHA),
                'textColor' => '#4b3011',
                'classNames' => 'ec-bucket',
                'extendedProps' => [
                    'bales' => $bucketRow['bales'],
                    'pct' => min(100, round($bucketRow['bales'] / $scale * 100, 1)),
                    'clamped' => $bucketRow['bales'] > $scale,
                    'fill' => $this->bandedFill($colors, array_column($bucketRow['parts'], 'bales')),
                ],
            ];
        });

        // The legend measures length, not shift, so it shows every colour on the grid in
        // even bands rather than picking one shift's colour to stand for all of them.
        $legendColors = $buckets
            ->flatMap(fn (array $bucketRow) => array_column($bucketRow['parts'], 'shift_id'))
            ->unique()
            ->sortBy(fn (int $shiftId) => $order[$shiftId] ?? PHP_INT_MAX)
            ->map(fn (int $shiftId) => $shiftColors[$shiftId] ?? $base)
            ->unique()
            ->values()
            ->all();

        return [$events, [
            'peak' => $peak,
            'scale' => $scale,
            'bucketSeconds' => $bucket,
            'buckets' => $buckets->count(),
            'fill' => $this->bandedFill($legendColors, array_fill(0, count($legendColors), 1)),
            'track' => $this->rgba($legendColors[0] ?? $base, self::DENSITY_TRACK_ALPHA),
        ]];
    }

    /**
     * Colour per shift id, agreeing with the day chips: a shift's sequence number picks its
     * slot in the shift palette, so a bar and the chip above it are never different colours.
     *
     * Shifts that produced nothing in the range never reach the chip map, so they are
     * appended in shift-day order — without that they would fall back to a colour another
     * shift already owns.
     *
     * @param  array<int, int>  $classMap  shift id => sequence number, as the chips use it
     * @param  list<array{id: int, name: string, until: int}>  $segments
     * @return array<int, string>
     */
    private function shiftColorMap(array $classMap, array $segments): array
    {
        $palette = config('colors.shift');
        $next = $classMap === [] ? 1 : max($classMap) + 1;

        foreach ($segments as $segment) {
            $classMap[$segment['id']] ??= $next++;
        }

        return array_map(
            fn (int $sequence) => $palette[$sequence] ?? config('colors.fibre.total'),
            $classMap
        );
    }

    /**
     * CSS background for a bar: one colour, or — where a bucket straddles the changeover —
     * hard-stop bands, each taking as much of the bar as its shift took of the bucket.
     *
     * @param  list<string>  $colors
     * @param  list<int>  $weights  parallel to $colors
     */
    private function bandedFill(array $colors, array $weights): string
    {
        if (count($colors) <= 1) {
            return $colors[0] ?? config('colors.fibre.total');
        }

        $total = array_sum($weights) ?: count($colors);
        $last = count($colors) - 1;
        $stops = [];
        $cursor = 0.0;

        foreach ($colors as $position => $color) {
            $end = $position === $last ? 100.0 : $cursor + $weights[$position] / $total * 100;
            $stops[] = sprintf('%s %.2f%% %.2f%%', $color, $cursor, $end);
            $cursor = $end;
        }

        return 'linear-gradient(to right, '.implode(', ', $stops).')';
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
     * Range totals per shift for the summary strip, folded up from the very rows that draw
     * the calendar chips so the strip and the calendar can never disagree.
     *
     * @param  Collection<int, object>  $shiftWiseTotals
     * @return array<string, array{total_bales: int, total_eb_price: int}>
     */
    private function calcShiftTotals(Collection $shiftWiseTotals): array
    {
        $totals = $shiftWiseTotals->reduce(function ($carry, $row) {
            $carry[$row->shift_name] ??= ['total_bales' => 0, 'total_eb_price' => 0.0];
            $carry[$row->shift_name]['total_bales'] += (int) $row->total_bales;
            $carry[$row->shift_name]['total_eb_price'] += (float) $row->total_eb_price;

            return $carry;
        }, []);

        // Round once on the summed price, not per day, so the strip still matches the
        // figure the old single SUM() produced
        return array_map(fn ($shift) => [
            'total_bales' => $shift['total_bales'],
            'total_eb_price' => (int) $shift['total_eb_price'],
        ], $totals);
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
                'total_bales' => $row->bales_produced,
                'total_eb_price' => $row->eb_price,
            ])
            ->reject(fn (object $row) => $row->total_bales <= 0)
            ->values();
    }

    /**
     * One all-day card per day: the day's total, then one row per shift, each row a bar
     * whose length is that shift's count on the week's scale, then the energy cost per
     * bale.
     *
     * The day is a single event rather than one event per shift so that every row in a
     * column shares the card's width — the bars then start on one left edge and the
     * counts end on one right edge, in each of the seven columns. Seven separate
     * shrink-to-fit chips cannot do that.
     *
     * Days inside the range with nothing on them still get a card, so the week reads as
     * seven columns rather than as gaps; days after the live shift day get nothing,
     * because they have not happened.
     */
    private function appendShiftBlocksAndDailyTotals($events, $shiftWiseTotals, $classMap, string $liveDay, string $startDate, string $endDate)
    {
        $inRange = collect($shiftWiseTotals)->reject(fn ($row) => $row->shift_date > $liveDay);
        $byDate = $inRange->groupBy('shift_date');

        // Every shift that ran this week keeps its own line in all seven columns, so a
        // day where only Shift 2 produced leaves Shift 1's line empty rather than
        // sliding Shift 2 up into it — the same shift then sits on the same line in
        // every column and the cards stay comparable row for row.
        $sequences = $inRange->map(fn ($row) => $classMap[$row->shift_id] ?? 0)
            ->unique()->sort()->values()->all();

        // One scale for the whole week, taken from its busiest shift: the bars are read
        // across the columns as much as down one, so a day cannot be allowed to
        // normalise against itself and draw its best shift full-length.
        $peak = (float) $inRange->max(fn ($row) => (int) $row->total_bales);

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
     * @param  Collection<int, object>  $rows  one row per shift that produced that day
     * @param  array<int, int>  $classMap  shift id => sequence number, which picks its colour
     * @param  list<int>  $sequences  shift sequences to reserve a line for, in order
     * @param  float  $peak  the week's largest shift count, which a full-length bar is worth
     */
    private function buildDayCard(Collection $rows, array $classMap, array $sequences, float $peak): string
    {
        $palette = config('colors.shift');
        $fallback = config('colors.fibre.total');

        $totalBales = (int) $rows->sum(fn ($row) => (int) $row->total_bales);
        $totalCost = (float) $rows->sum(fn ($row) => (float) $row->total_eb_price);

        // An empty day says so once, in place of the headline; the row keeps its height
        // from .fb-row-head so the shift lines below stay level across the week.
        $headline = $rows->isEmpty()
            ? "<span class='fb-chip'>no production</span>"
            : "<span class='fb-big'>".$this->formatBales($totalBales)."<span class='fb-unit'>bales</span></span>";

        // eb_price is filled by the nightly job, so the live day has none to show yet.
        $cost = $totalCost > 0
            ? "<span class='fb-total-cost'>".$this->formatEbPrice($totalCost).'</span>'
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
                $lines .= "<span class='fb-sline fb-sline-empty'></span>";
            }
        }

        $efficiency = '';
        if ($totalBales > 0 && $totalCost > 0) {
            $perBale = NumberFormatterService::formatToIndianCurrency($totalCost / $totalBales, 1);
            $efficiency = "<span class='fb-eff'>{$perBale} / bale</span>";
        }

        return "<div class='fb-card'>"
            ."<div class='fb-row fb-row-head'>{$headline}{$cost}</div>"
            .$lines
            .$efficiency
            .'</div>';
    }

    /**
     * One shift on one line: the row itself is the chart. A band runs to that shift's
     * share of the week's peak and a rule marks where it ends, with the count — and the
     * shift's energy cost, when the job has posted one — right-aligned beside it.
     */
    private function buildShiftRow(object $row, string $color, float $peak): string
    {
        $bales = (int) $row->total_bales;
        $cost = (float) $row->total_eb_price;
        $label = e($this->shortShiftLabel((string) $row->shift_name));

        $title = "{$row->shift_name}: ".$this->formatBales($bales).' bales';
        if ($cost > 0) {
            $title .= ' · '.NumberFormatterService::formatToIndianCurrency($cost, 0);
        }
        $title = e($title);

        $bar = '';
        if ($peak > 0 && $bales > 0) {
            $width = round(min($bales / $peak, 1) * self::BAR_MAX_WIDTH, 2);
            $bar = "<span class='fb-band' style='width:{$width}%; background:{$color};'></span>"
                ."<span class='fb-edge' style='left:{$width}%; background:{$color};'></span>";
        }

        return "<span class='fb-sline' title='{$title}'>"
            .$bar
            ."<span class='fb-stext'>"
            ."<span class='fb-lbl' style='color:{$color};'>{$label}</span>"
            ."<span class='fb-nums'><b>".$this->formatBales($bales).'</b>'
            .($cost > 0 ? " · <span class='fb-muted'>".$this->formatEbPrice($cost).'</span>' : '')
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

    private function formatBales(int $bales): string
    {
        return NumberFormatterService::formatToIndianFormat($bales, false, 0);
    }

    /**
     * Energy cost beside a count, or nothing at all when there is none to show — the
     * nightly job is what fills eb_price, so the live day would otherwise print a ₹0
     * that is not a reading.
     */
    private function formatEbPrice(float $cost): string
    {
        return $cost > 0 ? NumberFormatterService::formatToIndianCurrency($cost, 0, true) : '';
    }

    private function getShiftDateTimes(string $startDate, string $endDate, string $shiftStartTime)
    {
        if ($shiftStartTime === '00:00:00') {
            $startDateTime = Carbon::parse($startDate)->startOfDay();
            $endDateTime = Carbon::parse($endDate)->endOfDay();
        } else {
            $startDateTime = Carbon::parse($startDate)->setTimeFromTimeString($shiftStartTime);
            $endDateTime = Carbon::parse($endDate)->addDay()->setTimeFromTimeString($shiftStartTime)->subSecond();
        }

        return [$startDateTime, $endDateTime];
    }
}
