<?php

namespace App\Http\Controllers\Pulse\IoT;

use App\Http\Controllers\Controller;
use App\Models\Pulse\Device;
use App\Models\Pulse\Shift;
use App\Services\NumberFormatterService;
use App\Traits\ModuleRedirectTrait;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class IotPithController extends Controller
{
    use ModuleRedirectTrait;

    /**
     * Maps a raw power state to its grouped label (on/off/missing). This is the single
     * source of truth for the grouping used by the weekly schedule's On/Off <-> All toggle,
     * so the frontend never has to decide the mapping itself -- it just displays whichever
     * field (raw state or group) matches the currently selected view.
     */
    private const STATE_GROUPS = [
        'efficient' => 'on',
        'idle' => 'on',
        'overload' => 'on',
        'off' => 'off',
        'shutdown' => 'off',
        'missing' => 'off',
    ];

    /**
     * Machine states in rail and readout order: running states first, then stopped
     * ones. The readout renders this as a 3x2 grid filled column-major, so the left
     * column is always "was it working" and the right is always "why wasn't it".
     */
    private const STATE_SEQUENCE = ['efficient', 'idle', 'overload', 'off', 'shutdown', 'missing'];

    private const STATE_ABBREVIATIONS = [
        'efficient' => 'Eff',
        'idle' => 'Idle',
        'overload' => 'Over',
        'off' => 'Off',
        'shutdown' => 'Shut',
        'missing' => 'Miss',
    ];

    /**
     * Where 100% of the metered estimate sits along a shift row, as a percentage of
     * the bar's own width (see BAR_MAX_WIDTH, which the bar is scaled into — this
     * mark is scaled down along with it). Leaving 28% of headroom is what makes
     * beating the estimate visible: the entered bar runs past this mark instead of
     * clipping at the end, so 143% no longer draws identically to 100%. Rows above
     * ~139% cap at BAR_MAX_WIDTH and simply stop growing.
     */
    private const ESTIMATE_MARK = 72;

    /**
     * How far across the shift row the coloured bar is allowed to reach, as a
     * percentage of the row's width. The numbers (pd-nums) render right-aligned
     * in the same row, so capping the bar short of the full width keeps it from
     * running underneath — and visually competing with — the actual/estimate
     * readout instead of just sitting behind it as a low-opacity cue.
     */
    private const BAR_MAX_WIDTH = 60;

    private function groupForState(?string $state): string
    {
        return self::STATE_GROUPS[$state] ?? 'off';
    }

    /**
     * The date column may be selected by the client, so it is never interpolated
     * into SQL unchecked.
     */
    private function resolveDateField(?string $requested): string
    {
        return $requested === 'date' ? 'date' : 'shift_date';
    }

    public function index(Request $request)
    {
        $team = Auth::user()->currentTeam;
        if ($redirect = $this->handleMissingModules('has_pith_module')) {
            return $redirect;
        }

        $currentTeamId = Auth::user()->currentTeam->id;

        if (! Shift::activeForTeam($currentTeamId)->exists()) {
            abort(403, 'Please set shift start time in the settings!');
        }

        $shiftStartTime = Shift::anchorForTeam($currentTeamId);

        $colors = [
            'shifts' => config('colors.shift'),
            'stateColors' => config('colors.states'),
        ];

        $today = now()->toDateString();

        return view('pulse::iot-insights.pith.weekly_loads', array_merge(
            compact('shiftStartTime', 'today', 'colors'),
            ['teamId' => Auth::user()->currentTeam->id]
        ));
    }

    public function fetchDeviceEvents(Request $request)
    {
        // Get start and end date
        $startDate = Carbon::parse($request->query('start'))->format('Y-m-d');
        $endDate = Carbon::parse($request->query('end'))->format('Y-m-d');
        $dateField = $this->resolveDateField($request->query('format'));

        $currentTeamId = Auth::user()->currentTeam->id;
        $deviceId = Device::where('team_id', $currentTeamId)
            ->where('module', 'pith')
            ->where('status', 'active')
            ->value('device_id');
        // for current day(shift time)fetch data from pulse_device_data and use pulse_summary_power_states for historical data
        $shiftStartTime = Shift::anchorForTeam($currentTeamId);

        $now = Carbon::now();
        [$sh, $sm] = explode(':', $shiftStartTime);
        $shiftToday = $now->lt($now->copy()->setTime((int) $sh, (int) $sm, 0))
                        ? $now->copy()->subDay()->format('Y-m-d')
                        : $now->format('Y-m-d');

        // $calendarToday  = $now->format('Y-m-d');
        $todayIsInRange = ($shiftToday >= $startDate && $shiftToday <= $endDate);

        $summaryExists = $todayIsInRange && DB::table('pulse_summary_power_states')
            ->where('device_id', $deviceId)
            ->where($dateField, $shiftToday)
            ->exists();

        $histEnd = $todayIsInRange
            ? ($summaryExists ? $shiftToday : Carbon::parse($shiftToday)->subDay()->format('Y-m-d'))
            : $endDate;

        $events = DB::table('pulse_summary_power_states')
            ->where('device_id', $deviceId)
            ->whereBetween($dateField, [$startDate, $histEnd])
            ->get(['id', 'start_time', 'end_time', 'state']);

        $calendarEvents = $events->map(function ($event) {
            return [
                'id' => $event->id,
                'title' => ucfirst($event->state),
                'start' => $event->start_time,
                'end' => $event->end_time,
                'color' => config('colors.states')[$event->state],
                'extendedProps' => [
                    'state' => $event->state,
                    // Server-computed grouped label (on/off/missing). The frontend toggle
                    // reads this directly instead of re-deriving the mapping itself, so the
                    // grouping logic lives in exactly one place.
                    'group' => $this->groupForState($event->state),
                ],
            ];
        });

        // Live today — only if summary not written yet by scheduler
        if ($todayIsInRange && ! $summaryExists) {
            $team = Auth::user()->currentTeam;
            $nowDt = $now->toDateTimeString();
            $colors = config('colors.states');
            $states = app(IotPithApiController::class)
                ->fetchLiveProduction($team, $deviceId, $shiftToday, 'shift')['machineStates'] ?? [];

            foreach ($states as $i => $point) {
                if ($point['timestamp'] >= $nowDt) {
                    break;
                }

                $endTs = isset($states[$i + 1]) ? min($states[$i + 1]['timestamp'], $nowDt) : $nowDt;
                $rawState = $point['state'] === 'on' ? 'efficient' : 'off';

                $calendarEvents->push([
                    'id' => 'live-'.$i,
                    'title' => ucfirst($point['state']),
                    'start' => $point['timestamp'],
                    'end' => $endTs,
                    'color' => $colors[$rawState],
                    'extendedProps' => [
                        'state' => $rawState,
                        'group' => $this->groupForState($rawState),
                        'isLive' => true,
                    ],
                ]);
            }
        }

        foreach ($this->getAllDayShiftEvents($deviceId, $dateField, $startDate, $endDate) as $event) {
            $calendarEvents->push($event);
        }

        return response()->json([
            'events' => $calendarEvents,
            'totals' => $this->getFullTotals($deviceId, $dateField, $startDate, $endDate),
        ]);
    }

    public function fetchDeviceStates(Request $request)
    {
        // Get start and end date
        $startDate = Carbon::parse($request->query('start'))->format('Y-m-d');
        $endDate = Carbon::parse($request->query('end'))->format('Y-m-d');
        $dateField = $this->resolveDateField($request->query('format'));

        $currentTeamId = Auth::user()->currentTeam->id;
        $firstActiveDeviceId = Device::where('team_id', $currentTeamId)
            ->where('module', 'pith')
            ->where('status', 'active')
            ->value('device_id');

        // Query to get the summary of device state data and loads produced per day
        $events = DB::select("
                    SELECT
                        pulse_summary_power_states.{$dateField} AS date,
                        pulse_summary_power_states.state,
                        SUM(pulse_summary_power_states.duration) AS total_duration
                    FROM
                        pulse_summary_power_states
                    WHERE
                        pulse_summary_power_states.device_id = :deviceId
                        AND pulse_summary_power_states.{$dateField} BETWEEN :startDate AND :endDate
                    GROUP BY
                        pulse_summary_power_states.{$dateField},
                        pulse_summary_power_states.state
                    ORDER BY
                        pulse_summary_power_states.{$dateField}
                ", [
            'deviceId' => $firstActiveDeviceId,
            'startDate' => $startDate,
            'endDate' => $endDate,
        ]);

        // Initialize an array to hold the device state summary by day
        $deviceStateSummary = [];

        // Loop through the results and populate the summary
        foreach ($events as $event) {
            $dayOfWeek = strtolower(Carbon::parse($event->date)->format('D'));

            // If the day does not exist in the summary, initialize it
            if (! isset($deviceStateSummary[$dayOfWeek])) {
                $deviceStateSummary[$dayOfWeek] = [
                    'efficient' => 0,
                    'off' => 0,
                    'shutdown' => 0,
                    'overload' => 0,
                    'idle' => 0,
                    'missing' => 0,
                ];
            }

            // Increment the duration for the corresponding state
            if (array_key_exists($event->state, $deviceStateSummary[$dayOfWeek])) {
                $deviceStateSummary[$dayOfWeek][$event->state] += $event->total_duration;
            }
        }

        // Return the device states summary in JSON format
        return response()->json($deviceStateSummary);
    }

    private function getAllDayShiftEvents($deviceId, $dateField, $startDate, $endDate)
    {
        $showActuals = Auth::user()->currentTeam->showActuals();

        $shiftWiseTotals = DB::table('pulse_summary_pith_productions')
            ->where('device_id', $deviceId)
            ->whereBetween($dateField, [$startDate, $endDate])
            ->groupBy($dateField, 'shift_id')
            ->orderBy('shift_id', 'asc')
            ->select(
                $dateField,
                'shift_id',
                'shift_name as shift_name',
                DB::raw('SUM(cft_expected) as loads_expected'),
                DB::raw('SUM(cft_produced) as loads_produced'),
                DB::raw('SUM(eb_price) as total_eb_price')
            )
            ->get();

        $shiftColors = config('colors.shift');

        // Shift ids are per-team and arbitrary; collapse them to 1..3 so a shift's
        // sequence number, not its database id, picks its slot in the palette.
        $shiftIdMap = [];
        $nextShiftId = 1;
        $maxShiftId = 3;

        $days = [];
        foreach ($shiftWiseTotals as $row) {
            $metered = (float) ($row->loads_expected ?? 0);
            $entered = (float) ($row->loads_produced ?? 0);

            if ($metered <= 0.1 && $entered <= 0.1) {
                continue;
            }

            if (! isset($shiftIdMap[$row->shift_id])) {
                $shiftIdMap[$row->shift_id] = ($nextShiftId <= $maxShiftId) ? $nextShiftId++ : $maxShiftId;
            }
            $sequence = $shiftIdMap[$row->shift_id];

            $date = $row->{$dateField};
            $days[$date] ??= ['shifts' => [], 'eb_price' => 0.0];

            $days[$date]['shifts'][] = [
                'name' => $row->shift_name,
                'sequence' => $sequence,
                'color' => $shiftColors[$sequence],
                'metered' => $metered,
                'entered' => $entered,
                // cft_produced arrives as NULL until someone keys the shift in, and as
                // 0 for a few older rows. Both mean "no actual", so both fall back to
                // the metered estimate rather than dragging the day total down.
                'is_entered' => $entered > 0.1,
            ];
            $days[$date]['eb_price'] += (float) ($row->total_eb_price ?? 0);
        }

        $stateDurations = $this->getStateDurationsByDate($deviceId, $dateField, $startDate, $endDate);

        // Each shift keeps its own row across the whole week, whether or not it ran
        // that day. A day where only Shift 2 produced leaves Shift 1's row empty
        // rather than sliding Shift 2 up into it, so the same shift is always on the
        // same line in every column — and the machine tier below stays level too.
        $slotRowCounts = [];
        foreach ($days as $day) {
            $perSlot = [];
            foreach ($day['shifts'] as $shift) {
                $perSlot[$shift['sequence']] = ($perSlot[$shift['sequence']] ?? 0) + 1;
            }
            foreach ($perSlot as $sequence => $count) {
                $slotRowCounts[$sequence] = max($slotRowCounts[$sequence] ?? 0, $count);
            }
        }
        ksort($slotRowCounts);

        $dates = array_unique(array_merge(array_keys($days), array_keys($stateDurations)));
        sort($dates);

        $allDayShiftEvents = collect();
        foreach ($dates as $date) {
            $day = $days[$date] ?? ['shifts' => [], 'eb_price' => 0.0];
            $states = $stateDurations[$date] ?? [];

            if (empty($day['shifts']) && empty($states)) {
                continue;
            }

            $allDayShiftEvents->push([
                'title' => $this->buildOutputTier($day['shifts'], $slotRowCounts, $showActuals)
                            .$this->buildMachineTier($states, $day['eb_price']),
                'start' => $date,
                'end' => $date,
                'allDay' => true,
                'classNames' => 'ec-all-day-custom-total',
                'extendedProps' => $this->buildDayCardExtendedProps($date, $day['shifts'], $states, (float) $day['eb_price'], $showActuals),
            ]);
        }

        return $allDayShiftEvents;
    }

    /**
     * Structured data for the day card details popover on hover/click.
     *
     * @param  array<int, array<string, mixed>>  $shifts
     * @param  array<string, float>  $states
     */
    private function buildDayCardExtendedProps(string $date, array $shifts, array $states, float $ebPrice, bool $showActuals): array
    {
        $best = 0.0;
        $metered = 0.0;
        $enteredCount = 0;
        $shiftsData = [];

        foreach ($shifts as $shift) {
            $m = (float) $shift['metered'];
            $e = (float) $shift['entered'];
            $isEntered = (bool) $shift['is_entered'];

            $metered += $m;
            $best += $isEntered ? $e : $m;
            if ($isEntered) {
                $enteredCount++;
            }

            $delta = null;
            if ($isEntered && $m > 0) {
                $delta = (int) round((($e - $m) / $m) * 100);
            }

            $shiftsData[] = [
                'name' => $shift['name'],
                'label' => $this->shortShiftLabel($shift['name']),
                'sequence' => $shift['sequence'],
                'color' => $shift['color'],
                'metered' => $m,
                'meteredFormatted' => $this->formatCft($m),
                'entered' => $e,
                'enteredFormatted' => $this->formatCft($e),
                'is_entered' => $isEntered,
                'delta' => $delta,
            ];
        }

        $shiftCount = count($shifts);
        $basis = $showActuals ? $best : $metered;

        if ($shiftCount === 0) {
            $statusChip = 'No production';
            $statusType = 'no_production';
        } elseif ($showActuals && $enteredCount === $shiftCount) {
            $statusChip = 'All actual';
            $statusType = 'all_actual';
        } elseif ($showActuals && $enteredCount > 0) {
            $statusChip = "{$enteredCount} of {$shiftCount} actual";
            $statusType = 'partial_actual';
        } else {
            $statusChip = 'Estimate';
            $statusType = 'estimate';
        }

        $stateColors = config('colors.states');
        $runningSeconds = 0.0;
        foreach (self::STATE_GROUPS as $stateKey => $groupKey) {
            if ($groupKey === 'on') {
                $runningSeconds += $states[$stateKey] ?? 0;
            }
        }

        $statesBreakdown = [];
        foreach (self::STATE_SEQUENCE as $stateKey) {
            $sec = (float) ($states[$stateKey] ?? 0);
            if ($sec > 0) {
                $statesBreakdown[] = [
                    'state' => $stateKey,
                    'label' => ucfirst($stateKey === 'missing' ? 'data missing' : $stateKey),
                    'seconds' => $sec,
                    'formatted' => $this->formatDurationDecimal($sec),
                    'color' => $stateColors[$stateKey] ?? '#9ca3af',
                ];
            }
        }

        return [
            'date' => $date,
            'dateFormatted' => Carbon::parse($date)->isoFormat('dddd, D MMMM YYYY'),
            'basis' => $basis,
            'basisFormatted' => $this->formatCft($basis),
            'statusChip' => $statusChip,
            'statusType' => $statusType,
            'showActuals' => $showActuals,
            'shifts' => $shiftsData,
            'machine' => [
                'runningSeconds' => $runningSeconds,
                'runningFormatted' => $this->formatDurationDecimal($runningSeconds),
                'ebPrice' => $ebPrice,
                'ebPriceFormatted' => $ebPrice > 0
                    ? NumberFormatterService::formatToIndianCurrency($ebPrice, 0)
                    : '—',
                'states' => $statesBreakdown,
            ],
        ];
    }

    private function formatDurationDecimal(float $seconds): string
    {
        if ($seconds <= 0) {
            return '0h';
        }
        if ($seconds >= 3600) {
            return number_format($seconds / 3600, 1).'h';
        }
        if ($seconds >= 60) {
            return (string) round($seconds / 60).'m';
        }

        return (string) round($seconds).'s';
    }

    /**
     * Per-day machine-state durations in seconds, keyed by date then state.
     *
     * @return array<string, array<string, float>>
     */
    private function getStateDurationsByDate($deviceId, string $dateField, string $startDate, string $endDate): array
    {
        $rows = DB::table('pulse_summary_power_states')
            ->where('device_id', $deviceId)
            ->whereBetween($dateField, [$startDate, $endDate])
            ->groupBy($dateField, 'state')
            ->select($dateField, 'state', DB::raw('SUM(duration) as total_duration'))
            ->get();

        $durations = [];
        foreach ($rows as $row) {
            $durations[$row->{$dateField}][$row->state] = (float) $row->total_duration;
        }

        return $durations;
    }

    /**
     * Production tier: the day's best-available total, then one row per shift slot.
     *
     * @param  array<int, array<string, mixed>>  $shifts
     * @param  array<int, int>  $slotRowCounts  Rows to reserve per shift sequence.
     */
    private function buildOutputTier(array $shifts, array $slotRowCounts, bool $showActuals): string
    {
        $best = 0.0;
        $metered = 0.0;
        $enteredCount = 0;

        foreach ($shifts as $shift) {
            $metered += $shift['metered'];
            $best += $shift['is_entered'] ? $shift['entered'] : $shift['metered'];
            if ($shift['is_entered']) {
                $enteredCount++;
            }
        }

        $shiftCount = count($shifts);
        $basis = $showActuals ? $best : $metered;

        // No chip when every shift has an actual — that is the ordinary case and it
        // needs no annotation. A chip appears only when the headline is not purely
        // what staff entered, so its presence is the signal.
        if ($shiftCount === 0) {
            // No dash-and-unit placeholder: an empty day says so once. The row keeps
            // its height from .pd-row-head so the machine tier below stays level.
            $headline = "<span class='pd-chip pd-chip-flat'>no production</span>";
        } elseif ($showActuals && $enteredCount === $shiftCount) {
            $headline = "<span class='pd-big'>".$this->formatCft($basis)."<span class='pd-unit'>cft</span></span>";
        } elseif ($showActuals && $enteredCount > 0) {
            $headline = "<span class='pd-big'>".$this->formatCft($basis)."<span class='pd-unit'>cft</span></span>"
                       ."<span class='pd-chip pd-chip-flat'>{$enteredCount} of {$shiftCount} actual</span>";
        } else {
            $headline = "<span class='pd-big'>".$this->formatCft($basis)."<span class='pd-unit'>cft</span></span>"
                       ."<span class='pd-chip pd-chip-flat'>estimate</span>";
        }

        $bySlot = [];
        foreach ($shifts as $shift) {
            $bySlot[$shift['sequence']][] = $shift;
        }

        $rows = '';
        foreach ($slotRowCounts as $sequence => $reserved) {
            $inSlot = $bySlot[$sequence] ?? [];
            foreach ($inSlot as $shift) {
                $rows .= $this->buildShiftRow($shift, $showActuals);
            }
            for ($i = count($inSlot); $i < $reserved; $i++) {
                $rows .= "<span class='pd-sline pd-sline-empty'></span>";
            }
        }

        return "<div class='pd-tier'><div class='pd-row pd-row-head'>{$headline}</div>{$rows}</div>";
    }

    /**
     * One shift on one line: the row itself is the chart. A pale band runs to the
     * metered estimate, a stronger one to what staff entered, and a rule marks each.
     *
     * @param  array<string, mixed>  $shift
     */
    private function buildShiftRow(array $shift, bool $showActuals): string
    {
        $label = e($this->shortShiftLabel($shift['name']));
        $name = e($shift['name']);
        $color = $shift['color'];
        $metered = $shift['metered'];

        // Nothing to compare: either the team hides actuals, or nobody has entered
        // this shift yet. Both render the estimate as the only measurement.
        if (! $showActuals || ! $shift['is_entered']) {
            $reason = $showActuals ? 'no actual entered' : 'actuals hidden for this team';
            $title = e("{$name}: metered ".$this->formatCft($metered)." cft — {$reason}");

            return "<span class='pd-sline' title='{$title}'>"
                  ."<span class='pd-band pd-band-metered' style='width:".self::BAR_MAX_WIDTH."%; background:{$color};'></span>"
                  ."<span class='pd-stext'>"
                  ."<span class='pd-lbl' style='color:{$color};'>{$label}</span>"
                  ."<span class='pd-nums'><b>".$this->formatCft($metered).'</b> <span class="pd-muted">est</span></span>'
                  .'</span></span>';
        }

        $enteredValue = $shift['entered'];

        if ($metered <= 0) {
            // Entered production with no meter reading behind it — draw the entry
            // alone rather than dividing by zero.
            $title = e("{$name}: entered ".$this->formatCft($enteredValue).' cft — no metered estimate');

            return "<span class='pd-sline' title='{$title}'>"
                  ."<span class='pd-band pd-band-entered' style='width:".self::BAR_MAX_WIDTH."%; background:{$color};'></span>"
                  ."<span class='pd-stext'>"
                  ."<span class='pd-lbl' style='color:{$color};'>{$label}</span>"
                  ."<span class='pd-nums'><b>".$this->formatCft($enteredValue).'</b></span>'
                  .'</span></span>';
        }

        $raw = ($enteredValue / $metered) * self::ESTIMATE_MARK;
        $capped = $raw > 100;

        // Both marks are drawn within BAR_MAX_WIDTH rather than the row's full width,
        // so the bar never reaches into the space the right-aligned numbers occupy.
        $scale = self::BAR_MAX_WIDTH / 100;
        $estimateMark = self::ESTIMATE_MARK * $scale;
        $barWidth = min($raw, 100) * $scale;

        // The divergence is drawn, not written: the entered band running short of or
        // past the estimate's rule says it without spending a line on a percentage.
        // The exact figure stays in the hover title.
        $delta = (int) round((($enteredValue - $metered) / $metered) * 100);
        $title = e("{$name}: entered ".$this->formatCft($enteredValue).' · metered '
                  .$this->formatCft($metered).' cft ('.($delta > 0 ? '+' : '').$delta.'%)');

        $enteredEdge = $capped
            ? ''
            : "<span class='pd-edge pd-edge-entered' style='left:{$barWidth}%; background:{$color};'></span>";

        return "<span class='pd-sline' title='{$title}'>"
              ."<span class='pd-band pd-band-metered' style='width:{$estimateMark}%; background:{$color};'></span>"
              ."<span class='pd-band pd-band-entered' style='width:{$barWidth}%; background:{$color};'></span>"
              ."<span class='pd-edge' style='left:{$estimateMark}%; background:{$color};'></span>"
              .$enteredEdge
              ."<span class='pd-stext'>"
              ."<span class='pd-lbl' style='color:{$color};'>{$label}</span>"
              ."<span class='pd-nums'><b>".$this->formatCft($enteredValue).'</b> · '.$this->formatCft($metered).'</span>'
              .'</span></span>';
    }

    /**
     * Machine tier: run hours and the day's EB cost, then two rails and a readout.
     * Both rails are emitted every time and CSS decides which is visible, so the
     * On/Off <-> All States toggle is a repaint rather than a refetch.
     *
     * @param  array<string, float>  $states
     */
    private function buildMachineTier(array $states, float $ebPrice): string
    {
        $cost = $ebPrice > 0
            ? "<span class='pd-small pd-small-strong'>".NumberFormatterService::formatToIndianCurrency($ebPrice, 0).'</span>'
            : '';

        $total = array_sum($states);
        if ($total <= 0) {
            return "<div class='pd-tier'><span class='pd-cap'>machine</span>"
                  ."<div class='pd-row'><span class='pd-nodata'>No telemetry</span>{$cost}</div></div>";
        }

        $stateColors = config('colors.states');

        $running = 0.0;
        foreach (self::STATE_GROUPS as $state => $group) {
            if ($group === 'on') {
                $running += $states[$state] ?? 0;
            }
        }

        $groupedRail = $this->buildRail([
            ['seconds' => $running, 'color' => $stateColors['group_on'], 'label' => 'On'],
            ['seconds' => $total - $running, 'color' => $stateColors['group_off'], 'label' => 'Off'],
        ], $total, 'pd-rail-grouped');

        $detailedSegments = [];
        foreach (self::STATE_SEQUENCE as $state) {
            $detailedSegments[] = [
                'seconds' => $states[$state] ?? 0,
                'color' => $stateColors[$state],
                'label' => ucfirst($state === 'missing' ? 'data missing' : $state),
            ];
        }
        $detailedRail = $this->buildRail($detailedSegments, $total, 'pd-rail-detailed');

        $readout = '';
        foreach (self::STATE_SEQUENCE as $state) {
            $seconds = $states[$state] ?? 0;
            $classes = 'pd-scell';
            if ($seconds <= 0) {
                $classes .= ' pd-scell-zero';
            } elseif ($state === 'missing' && $seconds >= 3600) {
                $classes .= ' pd-scell-alarm';
            }

            $readout .= "<span class='{$classes}'>"
                       ."<i style='background:{$stateColors[$state]};'></i>"
                       ."<span class='pd-k'>".self::STATE_ABBREVIATIONS[$state].'</span>'
                       ."<span class='pd-v'>".$this->formatDuration($seconds).'</span>'
                       .'</span>';
        }

        return "<div class='pd-tier'>"
              ."<span class='pd-cap'>machine</span>"
              ."<div class='pd-row'>"
              ."<span class='pd-mid'>".$this->formatDuration($running)."<span class='pd-unit'>run</span></span>"
              .$cost
              .'</div>'
              .$groupedRail
              .$detailedRail
              ."<div class='pd-sgrid'>{$readout}</div>"
              .'</div>';
    }

    /**
     * @param  array<int, array{seconds: float, color: string, label: string}>  $segments
     */
    private function buildRail(array $segments, float $total, string $railClass): string
    {
        $bars = '';
        foreach ($segments as $segment) {
            if ($segment['seconds'] <= 0) {
                continue;
            }
            $width = ($segment['seconds'] / $total) * 100;
            $title = e($segment['label'].' '.$this->formatDuration($segment['seconds']));
            $bars .= "<span class='pd-seg' style='width:{$width}%; background:{$segment['color']};' title='{$title}'></span>";
        }

        return "<span class='pd-rail {$railClass}'>{$bars}</span>";
    }

    private function formatCft(float $value): string
    {
        return NumberFormatterService::formatToIndianFormat($value, false, 0);
    }

    private function formatDuration(float $seconds): string
    {
        if ($seconds <= 0) {
            return '&mdash;';
        }

        if ($seconds < 60) {
            return '&lt;1m';
        }

        $hours = $seconds / 3600;

        return $hours >= 1
            ? number_format($hours, 1).'h'
            : round($seconds / 60).'m';
    }

    /**
     * "Shift 1" -> "S1" so the label fits on one line beside its figures. Names
     * that don't follow that convention keep their first few characters.
     */
    private function shortShiftLabel(string $name): string
    {
        if (preg_match('/^shift\s*(\S+)$/i', trim($name), $matches) === 1) {
            return 'S'.$matches[1];
        }

        return mb_substr($name, 0, 4);
    }

    private function getFullTotals($deviceId, $dateField, $startDate, $endDate)
    {
        // Get complete week's data
        $totalData = DB::table('pulse_summary_pith_productions')
            ->where('device_id', $deviceId)
            ->whereBetween($dateField, [$startDate, $endDate])
            ->groupBy('shift_name')
            ->orderBy('shift_id', 'asc')
            ->select('shift_name as shift_name', DB::raw('SUM(cft_expected) as loads_expected'), DB::raw('SUM(cft_produced) as loads_produced'), DB::raw('SUM(eb_price) as total_eb_price'))
            ->get();

        // Get previous period data for comparison
        $daysDiff = Carbon::parse($startDate)->diffInDays(Carbon::parse($endDate)) + 1;
        $prevStartDate = Carbon::parse($startDate)->subDays($daysDiff)->format('Y-m-d');
        $prevEndDate = Carbon::parse($startDate)->subDay()->format('Y-m-d');

        $prevTotalData = DB::table('pulse_summary_pith_productions')
            ->where('device_id', $deviceId)
            ->whereBetween($dateField, [$prevStartDate, $prevEndDate])
            ->groupBy('shift_name')
            ->select('shift_name', DB::raw('SUM(cft_produced) as loads_produced'))
            ->get()
            ->keyBy('shift_name');

        // Get daily data for best/worst day and production rate
        $dailyData = DB::table('pulse_summary_pith_productions')
            ->where('device_id', $deviceId)
            ->whereBetween($dateField, [$startDate, $endDate])
            ->groupBy($dateField)
            ->select($dateField.' as date', DB::raw('SUM(cft_produced) as loads_produced'))
            ->orderBy('loads_produced', 'desc')
            ->get();

        // Ensure totals array is structured correctly
        $totals = [];
        $totalProduced = 0;
        $totalPrevProduced = 0;

        foreach ($totalData as $row) {
            $currentProduced = round($row->loads_produced ?? 0, 1);
            $prevProduced = $prevTotalData->get($row->shift_name)?->loads_produced ?? 0;

            $percentageChange = 0;
            if ($prevProduced > 0) {
                $percentageChange = round((($currentProduced - $prevProduced) / $prevProduced) * 100, 1);
            }

            $totals[$row->shift_name] = [
                'loads_expected' => round($row->loads_expected ?? 0, 1),
                'loads_produced' => $currentProduced,
                'total_eb_price' => (int) $row->total_eb_price,
                'percentage_change' => $percentageChange,
                'prev_produced' => round($prevProduced, 1),
            ];

            $totalProduced += $currentProduced;
            $totalPrevProduced += $prevProduced;
        }

        return $totals;
    }
}
