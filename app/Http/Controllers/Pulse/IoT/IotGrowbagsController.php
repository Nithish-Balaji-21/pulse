<?php

namespace App\Http\Controllers\Pulse\IoT;

use App\Http\Controllers\Controller;
use App\Models\Pulse\Shift;
use App\Services\NumberFormatterService;
use App\Services\Pulse\GrowbagShiftDayTotals;
use App\Support\Pulse\IotLiveDay;
use App\Traits\AttributesReadingsToShifts;
use App\Traits\ModuleRedirectTrait;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class IotGrowbagsController extends Controller
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

    /**
     * How far across a shift row the coloured band may reach, as a percentage of the
     * row's width. The counts render right-aligned in the same row, so the band stops
     * short of the full width instead of running underneath them. Bags carry no
     * estimate to compare against, so unlike the pith card there is no second mark —
     * the band alone is the magnitude.
     */
    private const BAR_MAX_WIDTH = 58;

    public function __construct(private readonly GrowbagShiftDayTotals $shiftDayTotals) {}

    public function index(Request $request)
    {
        $team = Auth::user()->currentTeam;
        if ($redirect = $this->handleMissingModules('has_growbags_module')) {
            return $redirect;
        }

        $today = now()->toDateString();
        $currentTeamId = Auth::user()->currentTeam->id;

        $shiftStartTime = Shift::anchorForTeam($currentTeamId);

        // The column the Live badge belongs on: the shift day in progress, which before
        // the anchor time is still yesterday's date.
        $liveDay = IotLiveDay::forAnchor($shiftStartTime);

        $machines = DB::table('pulse_master_growbag_machines')
            ->where('team_id', $currentTeamId)
            ->where('is_active', 1)
            ->orderBy('field_id')
            ->get(['id', 'field_id', 'machine_name']);

        // Always resolve to a concrete machine ID — never leave null
        $selectedMachineId = $request->query('machine', $machines->first()?->id);

        $colors = [
            'shifts' => config('colors.shift'),
            'totals' => config('colors.iot_totals.total'),
        ];

        return view('pulse::iot-insights.growbags.weekly_bags', array_merge(
            compact('shiftStartTime', 'today', 'liveDay', 'colors', 'machines', 'selectedMachineId'),
            ['teamId' => $currentTeamId]
        ));
    }

    public function fetchEventsByDate(Request $request)
    {
        $currentTeamId = Auth::user()->currentTeam->id;
        $machineId = $request->query('machine');

        $shiftStartTime = Shift::anchorForTeam($currentTeamId);
        $liveDay = IotLiveDay::forAnchor($shiftStartTime);

        $startDate = Carbon::parse($request->query('start'))->format('Y-m-d');
        $endDate = Carbon::parse($request->query('end'))->format('Y-m-d');

        [$fromDateTime, $toDateTime] = $this->getShiftDateTimes($startDate, $endDate, $shiftStartTime);

        $deviceIds = DB::table('pulse_devices')
            ->where('team_id', $currentTeamId)
            ->pluck('device_id');

        // summary_growbags is the reliable source — filter by machine here
        $shiftWiseTotals = $this->calcShiftWiseDayTotals($currentTeamId, $startDate, $endDate, $machineId);

        // folded up from the day buckets, so the strip and the calendar cannot disagree
        $totals = $this->calcShiftTotals($shiftWiseTotals, $currentTeamId);

        // Build shift number map from summary (shift_id 19 → 1, 21 → 2, etc.)
        $shift_nos = [];
        $i = 1;
        foreach ($shiftWiseTotals as $shiftTotal) {
            if (! isset($shift_nos[$shiftTotal->shift_id])) {
                $shift_nos[$shiftTotal->shift_id] = $i++;
            }
        }

        // Build date → shift_id fallback map (for NULL shift_id in raw events)
        // Uses the FIRST shift found per date from summary as the default color
        $dateShiftMap = [];
        foreach ($shiftWiseTotals as $row) {
            if (! isset($dateShiftMap[$row->shift_date])) {
                $dateShiftMap[$row->shift_date] = $row->shift_id;
            }
        }

        // The machines the range covers, and what a punch on each is worth. Raw rows count
        // punches; every figure the page shows is in bags, which is what the summary table
        // and the chips above the grid are in.
        $bagsPerPunch = $this->bagsPerPunchByField($currentTeamId, $machineId);

        $density = null;

        if ($request->query('mode') === 'density') {
            $segments = $this->shiftDaySegments(
                Shift::activeForTeam($currentTeamId)->get(),
                $shiftStartTime
            );

            [$events, $density] = $this->bucketedEvents(
                $deviceIds,
                $fromDateTime,
                $toDateTime,
                $this->resolveBucketSeconds($request->query('bucket')),
                $shiftStartTime,
                $segments,
                $this->shiftColorMap($shift_nos, $segments),
                $bagsPerPunch
            );
        } else {
            // Raw events
            $eventsRaw = DB::table('pulse_device_data_growbags')
                ->select('shift_id', 'growbag_datetime', 'field_id')
                ->whereIn('device_id', $deviceIds)
                ->whereBetween('growbag_datetime', [$fromDateTime, $toDateTime])
                ->where('is_duplicate', 0)
                ->when($bagsPerPunch !== [], fn ($query) => $query->whereIn('field_id', array_keys($bagsPerPunch)))
                ->get();

            $events = $eventsRaw->map(function ($event) use ($shift_nos, $dateShiftMap, $bagsPerPunch) {
                $shiftId = $event->shift_id;

                // shift_id is NULL in device_data_growbags — fall back to
                // whichever shift ran on that calendar date per summary table
                if (! $shiftId) {
                    $eventDate = Carbon::parse($event->growbag_datetime)->toDateString();
                    $shiftId = $dateShiftMap[$eventDate] ?? null;
                }

                $class = ($shiftId && array_key_exists($shiftId, $shift_nos))
                    ? $shift_nos[$shiftId]
                    : '1'; // default to shift1 color

                return [
                    'start' => $event->growbag_datetime,
                    'end' => $event->growbag_datetime,
                    'classNames' => 'shift'.$class.'-color ec-event-height',
                    'extendedProps' => [
                        'bags' => $bagsPerPunch[$event->field_id] ?? 1,
                    ],
                ];
            });
        }

        $events = $this->appendShiftBlocksAndDailyTotals($events, $shiftWiseTotals, $shift_nos, $liveDay, $startDate, $endDate);

        return response()->json([
            'events' => $events,
            'totals' => $totals,
            'density' => $density,
            'today' => IotLiveDay::today(),
            'liveDay' => $liveDay,
            'colors' => ['shifts' => config('colors.shift')],
        ]);
    }

    /**
     * field_id => bags a single punch on that machine produces, for the machine in view or
     * every active machine when none is selected.
     *
     * Machines with no field_id are left out: they cannot be matched to a device row, and a
     * blank key would swallow the rows whose field_id was never stamped.
     *
     * @return array<string, int>
     */
    private function bagsPerPunchByField($teamId, $machineId): array
    {
        return DB::table('pulse_master_growbag_machines')
            ->where('team_id', $teamId)
            ->where('is_active', 1)
            ->when($machineId, fn ($query) => $query->where('id', $machineId))
            ->whereNotNull('field_id')
            ->where('field_id', '!=', '')
            ->pluck('bags_per_punch', 'field_id')
            ->map(fn ($bags) => max(1, (int) $bags))
            ->all();
    }

    private function resolveBucketSeconds(mixed $requested): int
    {
        $bucket = (int) $requested;

        return in_array($bucket, self::BUCKET_SECONDS, true) ? $bucket : 1800;
    }

    /**
     * Time-grid marks as one block per fixed-width time bucket, shaded by how many bags
     * landed in it, rather than one 4px sliver per punch.
     *
     * Buckets are measured from $from — the grid's own origin — instead of the Unix epoch,
     * so a bucket edge lands on a slot edge on every day of the week.
     *
     * A bucket is worth the distinct counter values that landed in it, times the machine's
     * bags per punch. Distinct counters rather than a row count, because the device repeats
     * readings; and rather than differencing the bucket boundaries, because the stream
     * arrives out of order and any boundary difference mis-attributes punches across the edge.
     *
     * Counting is split by shift so each bar can carry its shift's colour. Readings are
     * placed by their time of day rather than by the stored shift_id, which the nightly job
     * only stamps once the shift day is over — grouping on it would leave the whole live day
     * uncoloured.
     *
     * @param  list<array{id: int, name: string, until: int}>  $segments
     * @param  array<int, string>  $shiftColors
     * @param  array<string, int>  $bagsPerPunch
     * @return array{0: Collection<int, array<string, mixed>>, 1: array<string, mixed>}
     */
    private function bucketedEvents($deviceIds, Carbon $from, Carbon $to, int $bucket, string $shiftStart, array $segments, array $shiftColors, array $bagsPerPunch): array
    {
        $origin = $from->format('Y-m-d H:i:s');
        $base = config('colors.growbags.total');

        // Left-to-right order of the bands inside a bucket the boundary runs through, and
        // the order the legend lists the shifts in: chronological within the shift day.
        $order = array_flip(array_column($segments, 'id'));
        $shiftIdSql = $segments === []
            ? '0'
            : $this->shiftIdCaseSql($segments, $shiftStart, 'growbag_datetime');

        $buckets = DB::table('pulse_device_data_growbags')
            ->whereIn('device_id', $deviceIds)
            ->where('is_duplicate', 0)
            ->whereBetween('growbag_datetime', [$from, $to])
            ->when($bagsPerPunch !== [], fn ($query) => $query->whereIn('field_id', array_keys($bagsPerPunch)))
            ->selectRaw(
                "FLOOR(TIMESTAMPDIFF(SECOND, '{$origin}', growbag_datetime) / {$bucket}) as bucket_index,
                 {$shiftIdSql} as bucket_shift_id,
                 field_id as bucket_field_id,
                 COUNT(DISTINCT growbags_counter) as punches"
            )
            // Grouping on the aliases, not the raw expressions: MySQL resolves an
            // unqualified GROUP BY name against the FROM clause first, so an alias of
            // `shift_id` would silently group on the table's own (still null) column.
            ->groupBy('bucket_index', 'bucket_shift_id', 'bucket_field_id')
            ->get()
            ->groupBy('bucket_index')
            ->map(fn (Collection $rows, $index) => [
                'index' => (int) $index,
                'bags' => (int) $rows->sum(fn ($row) => $this->bagsOf($row, $bagsPerPunch)),
                'parts' => $rows
                    // A bucket the shift boundary runs through gets one band per shift, so
                    // the machines running in it fold together before the bands are drawn.
                    ->groupBy('bucket_shift_id')
                    ->map(fn (Collection $shiftRows, $shiftId) => [
                        'shift_id' => (int) $shiftId,
                        'bags' => (int) $shiftRows->sum(fn ($row) => $this->bagsOf($row, $bagsPerPunch)),
                    ])
                    ->filter(fn (array $part) => $part['bags'] > 0)
                    ->sortBy(fn (array $part) => $order[$part['shift_id']] ?? PHP_INT_MAX)
                    ->values()
                    ->all(),
            ])
            ->filter(fn (array $bucketRow) => $bucketRow['bags'] > 0)
            ->sortBy('index')
            ->values();

        $sorted = $buckets->pluck('bags')->sort()->values();
        $peak = (int) $sorted->last() ?: 1;

        // A full bar is the 95th percentile, not the peak. Scaling to the peak lets a
        // handful of outlier buckets squash a typical one to a third of the width, which
        // hides exactly the run-to-run differences the view exists to show; the few
        // buckets above the scale clamp and are flagged instead.
        $scale = max(1, (int) $sorted->get((int) floor(($sorted->count() - 1) * 0.95)));

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
                    'bags' => $bucketRow['bags'],
                    'pct' => min(100, round($bucketRow['bags'] / $scale * 100, 1)),
                    'clamped' => $bucketRow['bags'] > $scale,
                    'fill' => $this->bandedFill($colors, array_column($bucketRow['parts'], 'bags')),
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
     * @param  array<string, int>  $bagsPerPunch
     */
    private function bagsOf(object $row, array $bagsPerPunch): int
    {
        return (int) $row->punches * ($bagsPerPunch[$row->bucket_field_id] ?? 1);
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
            fn (int $sequence) => $palette[$sequence] ?? config('colors.growbags.total'),
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
            return $colors[0] ?? config('colors.growbags.total');
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
     * @return array<string, array{total_bags: int, total_eb_price: int}>
     */
    private function calcShiftTotals(Collection $shiftWiseTotals, $teamId): array
    {
        $summed = $shiftWiseTotals->reduce(function ($carry, $row) {
            $carry[$row->shift_name] ??= ['total_bags' => 0, 'total_eb_price' => 0.0];
            $carry[$row->shift_name]['total_bags'] += (int) $row->total_bags;
            $carry[$row->shift_name]['total_eb_price'] += (float) $row->total_eb_price;

            return $carry;
        }, []);

        // Round once on the summed price, not per day, so the strip still matches the
        // figure the old single SUM() produced
        $totals = array_map(fn ($shift) => [
            'total_bags' => $shift['total_bags'],
            'total_eb_price' => (int) $shift['total_eb_price'],
        ], $summed);

        if (empty($totals)) {
            $activeShifts = Shift::where('team_id', $teamId)->where('is_active', 1)->pluck('name');
            foreach ($activeShifts as $sName) {
                $totals[$sName] = [
                    'total_bags' => 0,
                    'total_eb_price' => 0,
                ];
            }
        }

        return $totals;
    }

    /**
     * The range's shift figures, from the one resolver every growbag screen reads, folded
     * from a row per machine to a row per shift and projected onto the field names this
     * calendar's cards and summary strip expect.
     */
    private function calcShiftWiseDayTotals($teamId, $startDate, $endDate, $machineId = null): Collection
    {
        return $this->shiftDayTotals->forRange($teamId, $startDate, $endDate, $machineId)
            ->groupBy(fn (object $row) => $row->shift_date.'|'.$row->shift_id)
            ->map(fn (Collection $rows) => (object) [
                'shift_date' => $rows->first()->shift_date,
                'shift_id' => $rows->first()->shift_id,
                'shift_name' => $rows->first()->shift_name,
                'total_bags' => $rows->sum('bags_produced'),
                'total_eb_price' => $rows->sum('eb_price'),
            ])
            ->reject(fn (object $row) => $row->total_bags <= 0)
            ->sortBy([['shift_id', 'asc'], ['shift_date', 'asc']])
            ->values();
    }

    /**
     * One all-day card per day: the day's total, then one row per shift, each row a bar
     * whose length is that shift's count on the week's scale, then the energy cost per
     * bag.
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
        $peak = (float) $inRange->max(fn ($row) => (int) $row->total_bags);

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
        $fallback = config('colors.growbags.total');

        $totalBags = (int) $rows->sum(fn ($row) => (int) $row->total_bags);
        $totalCost = (float) $rows->sum(fn ($row) => (float) $row->total_eb_price);

        // An empty day says so once, in place of the headline; the row keeps its height
        // from .gb-row-head so the shift lines below stay level across the week.
        $headline = $rows->isEmpty()
            ? "<span class='gb-chip'>no production</span>"
            : "<span class='gb-big'>".$this->formatBags($totalBags)."<span class='gb-unit'>bags</span></span>";

        // eb_price is filled by the nightly job, so the live day has none to show yet.
        $cost = $totalCost > 0
            ? "<span class='gb-total-cost'>".$this->formatEbPrice($totalCost).'</span>'
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
                $lines .= "<span class='gb-sline gb-sline-empty'></span>";
            }
        }

        $efficiency = '';
        if ($totalBags > 0 && $totalCost > 0) {
            $perBag = NumberFormatterService::formatToIndianCurrency($totalCost / $totalBags, 2);
            $efficiency = "<span class='gb-eff'>{$perBag} / bag</span>";
        }

        return "<div class='gb-card'>"
            ."<div class='gb-row gb-row-head'>{$headline}{$cost}</div>"
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
        $bags = (int) $row->total_bags;
        $cost = (float) $row->total_eb_price;
        $label = e($this->shortShiftLabel((string) $row->shift_name));

        $title = "{$row->shift_name}: ".$this->formatBags($bags).' bags';
        if ($cost > 0) {
            $title .= ' · '.NumberFormatterService::formatToIndianCurrency($cost, 0);
        }
        $title = e($title);

        $bar = '';
        if ($peak > 0 && $bags > 0) {
            $width = round(min($bags / $peak, 1) * self::BAR_MAX_WIDTH, 2);
            $bar = "<span class='gb-band' style='width:{$width}%; background:{$color};'></span>"
                ."<span class='gb-edge' style='left:{$width}%; background:{$color};'></span>";
        }

        return "<span class='gb-sline' title='{$title}'>"
            .$bar
            ."<span class='gb-stext'>"
            ."<span class='gb-lbl' style='color:{$color};'>{$label}</span>"
            ."<span class='gb-nums'><b>".$this->formatBags($bags).'</b>'
            .($cost > 0 ? " · <span class='gb-muted'>".$this->formatEbPrice($cost).'</span>' : '')
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

    private function formatBags(int $bags): string
    {
        return NumberFormatterService::formatToIndianFormat($bags, false, 0);
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

    private function getShiftDateTimes(string $startDate, string $endDate, string $shiftStartTime): array
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
