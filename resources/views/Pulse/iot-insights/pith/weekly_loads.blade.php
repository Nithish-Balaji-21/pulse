<x-app-layout>
    <style>
        .event-tooltip {
            position: absolute;
            background-color: #333;
            color: #fff;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 12px;
            z-index: 1000;
            pointer-events: none;
        }
        .ec-header .ec-days {
            border-bottom: none;
        }
        .ec-with-scroll .ec-hidden-scroll {
            display: none !important;
        }
        .ec-body {
            overflow-y: hidden !important;
        }
        .ec-time-grid .ec-lines {
            width: 0 !important;
        }
        .ec-all-day {
            background-color: #f5f3f2;
            position: relative !important;
            top: auto !important;
            z-index: 30;
            transition: all 0.3s ease-in-out;
            overflow: visible;
            max-height: none;
        }
        /* ── Sticky stack ────────────────────────────────────────────────
           Three things pin as the page scrolls: the app's navigation at the top
           (50px tall), the calendar toolbar under it (iotinsights.css, top: 50px),
           and the day-name row under that. iotinsights.css pins the day-name row at
           a hard-coded 123px, i.e. it assumes a 73px toolbar — this page's toolbar
           carries the week summary and is taller, and the shortfall showed as a strip
           of the grid scrolling through the gap between the two. The offset is
           measured instead (see syncStickyHeaderOffset), with 123px as the fallback.

           The all-day band is deliberately not pinned: pinning the day cards on top
           of a pinned toolbar and day-name row leaves almost no grid visible. */
        #calendar .ec-header {
            top: var(--ec-header-top, 123px);
            z-index: 35;
        }
        .ec-all-day .ec-events {
            margin: 0px;
            padding: 0 4px;
        }
        /* Let the day card use the column it is given. The gutters came from four
           places stacked on top of each other — the events container's 6px, the
           library's 2px on .ec-event, the flex centering on .ec-event-body, and the
           event's own computed width — which together left the card floating in the
           middle of its column. #calendar out-specifies iotinsights.css, which is
           linked after this block via the header include. */
        #calendar .ec-all-day .ec-day .ec-events {
            padding: 0 2px !important;
            margin: 0 !important;
        }
        #calendar .ec-all-day .ec-event {
            padding: 0;
            /* !important because the library writes the width inline on the article. */
            width: 100% !important;
        }
        #calendar .ec-all-day .ec-event-body {
            display: block;
            width: 100%;
            place-content: normal;
        }
        #calendar .ec-all-day .all-day-event,
        #calendar .ec-all-day .all-day-event-title {
            display: block;
            width: 100%;
        }
        /* Top-align every all-day cell so all seven day cards share one baseline.
           iotinsights.css centres each cell's content vertically (align-items: center
           on .ec-days, place-content: center on .ec-day), which pushes a day with
           fewer shift rows down by half the missing height. iotinsights.css is linked
           after this <style> block (via the header include), hence the !important. */
        .ec-all-day .ec-days {
            align-items: stretch !important;
        }
        .ec-all-day .ec-day {
            padding-bottom: 8px !important;
            align-content: start !important;
        }
        .ec-all-day-custom-total {
            border-width: 1px !important;
            border-style: solid !important;
            border-color: #d5cec6;
            background-color: white !important;
            color: black !important;
            margin-top: 0 !important;
            padding: 0px !important;
            cursor: pointer;
            transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
        }
        .ec-all-day-custom-total:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08) !important;
            border-color: #a8a29e !important;
        }
        .ec-all-day-custom-total.is-pinned {
            border-color: #2563eb !important;
            box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.35), 0 4px 14px rgba(37, 99, 235, 0.18) !important;
        }
        .ec-event-body {
            place-content: center;
        }
        .all-day-event {
            width: 100%;
        }
        .all-day-event-title {
            display: flex;
            flex-direction: column;
            align-items: stretch;
            gap: 2px;
        }

        /* ── Day card ────────────────────────────────────────────────────────────
           The card is built server-side in IotPithController::buildOutputTier() and
           friends; everything below is the styling for that markup. Numbers are the
           primary carrier here and the tints are a supporting cue, which is why the
           bands sit behind the text at low opacity rather than the text sitting
           inside a saturated fill. */
        .pd-tier {
            display: flex;
            flex-direction: column;
            gap: 3px;
            padding: 5px 5px;
            font-variant-numeric: tabular-nums;
        }
        .pd-tier + .pd-tier {
            border-top: 1px solid #eee8e0;
        }
        .pd-row {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 5px;
        }
        /* The headline keeps its height even when a day has nothing to put in it,
           so the machine tier below stays level across all seven columns. */
        .pd-row-head {
            min-height: 19px;
        }
        .pd-cap {
            font-size: 12px;
            letter-spacing: 0.06em;
            font-weight: 600;
            text-transform: uppercase;
            color: #74695f;
        }
        .pd-big {
            font-size: 16px;
            font-weight: 600;
            color: #241d19;
            line-height: 1.15;
            font-variant-numeric: tabular-nums;
        }
        .pd-mid {
            font-size: 13px;
            font-weight: 600;
            color: #241d19;
            font-variant-numeric: tabular-nums;
        }
        .pd-small {
            font-size: 12px;
            color: #74695f;
        }
        .pd-small-strong {
            color: #574d45;
        }
        .pd-unit {
            font-size: 12px;
            font-weight: 400;
            color: #74695f;
            margin-left: 4px;
        }
        .pd-muted {
            color: #74695f;
        }
        .pd-nodata {
            font-size: 12px;
            color: #74695f;
            font-style: italic;
        }

        .pd-chip {
            font-size: 12px;
            font-weight: 600;
            padding: 1px 5px;
            border-radius: 3px;
            white-space: nowrap;
        }
        .pd-chip-flat { background: #f2ede6; color: #74695f; }

        /* One shift per line: the row is the chart. */
        .pd-sline {
            position: relative;
            display: block;
            height: 20px;
            border-radius: 3px;
            background: #fbf9f6;
            overflow: hidden;
        }
        .pd-sline + .pd-sline {
            margin-top: 3px;
        }
        .pd-sline-empty {
            background: transparent;
            border: 1px dashed #eee8e0;
        }
        .pd-band {
            position: absolute;
            top: 0;
            bottom: 0;
            left: 0;
        }
        .pd-band-metered { opacity: 0.13; }
        .pd-band-entered { opacity: 0.28; }
        .pd-edge {
            position: absolute;
            top: 0;
            bottom: 0;
            width: 1px;
            opacity: 0.55;
        }
        .pd-edge-entered {
            width: 2px;
            opacity: 0.95;
        }
        .pd-stext {
            position: relative;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 4px;
            height: 100%;
            padding: 0 5px;
            white-space: nowrap;
        }
        /* The shift's own colour carries its identity now that the row has no
           percentage on it — it is the only thing separating S1 from S2 at a
           glance, and it matches the palette the toolbar strip already uses. */
        .pd-lbl { font-size: 12px; font-weight: 600; }
        .pd-nums { font-size: 12px; color: #74695f; font-variant-numeric: tabular-nums; }
        .pd-nums b { font-size: 12px; font-weight: 600; color: #241d19; }

        /* State rail: full width is the day's recorded telemetry, not a fixed 24h,
           so a partially-recorded day still reads as a complete bar. */
        .pd-rail {
            display: flex;
            height: 10px;
            border-radius: 3px;
            overflow: hidden;
            background: #e0d9d0;
        }
        .pd-seg {
            height: 100%;
            min-width: 2px;
        }

        /* Individual-state readout, 3 rows filled column-major: running states in
           the left column, stopped states in the right. Always all six, so the grid
           never reflows and days stay comparable line-for-line. */
        .pd-sgrid {
            display: grid;
            grid-template-rows: repeat(3, auto);
            grid-auto-flow: column;
            grid-auto-columns: 1fr;
            column-gap: 8px;
            row-gap: 1px;
            font-size: 12px;
        }
        .pd-scell {
            display: flex;
            align-items: center;
            gap: 4px;
            white-space: nowrap;
            color: #574d45;
        }
        .pd-scell i {
            width: 6px;
            height: 6px;
            border-radius: 1.5px;
            flex: none;
        }
        .pd-scell .pd-k { color: #74695f; }
        .pd-scell .pd-v { margin-left: auto; font-weight: 600; color: #241d19; }
        .pd-scell-zero { opacity: 0.6; }
        .pd-scell-zero .pd-v { font-weight: 400; color: #74695f; }
        .pd-scell-alarm .pd-v,
        .pd-scell-alarm .pd-k { color: {{ $colors['stateColors']['missing'] }}; font-weight: 600; }

        /* The All States toggle only swaps which rail is visible and reveals the
           readout — no relayout, no refetch, no chart rebuild. */
        .pd-rail-detailed,
        .pd-sgrid {
            display: none;
        }
        #calendar.pd-detailed .pd-rail-grouped {
            display: none;
        }
        #calendar.pd-detailed .pd-rail-detailed {
            display: flex;
        }
        #calendar.pd-detailed .pd-sgrid {
            display: grid;
        }
        .ec-days .ec-day time {
            text-decoration: none;
        }
        .ec-days .ec-day time:hover {
            color: inherit;
        }
        /* Optional: Style the calendar icon */
        .ec-toolbar .ec-title i.fa-calendar {
            font-size: 1rem;
            color: #007bff;
        }
        .ec-toolbar .ec-title {
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-left: auto;
            margin-right: auto;
            justify-content: center;
            text-align: center;
        }
        .ec-all-day-states {
            background-color: #f5f3f2 !important;
        }

        /* Custom styles for the event states */
        .event-efficient {
            left: 10% !important;
            width: 3% !important;
            background-color: {{ $colors['stateColors']['efficient'] }} !important;
        }
        .event-idle {
            left: 20% !important;
            width: 3% !important;
            background-color: {{ $colors['stateColors']['idle'] }} !important;
        }
        .event-overload {
            left: 35% !important;
            width: 3% !important;
            background-color: {{ $colors['stateColors']['overload'] }} !important;
        }
        .event-off {
            left: 50% !important;
            width: 3% !important;
            background-color: {{ $colors['stateColors']['off'] }} !important;
        }
        .event-shutdown {
            left: 65% !important;
            width: 3% !important;
            background-color: {{ $colors['stateColors']['shutdown'] }} !important;
        }
        .event-missing {
            left: 75% !important;
            width: 3% !important;
            background-color: {{ $colors['stateColors']['missing'] }} !important;
        }

        /*css for on off*/
        .event-group-on {
            left: 15% !important;
            width: 3% !important;
            background-color: {{ $colors['stateColors']['group_on'] }} !important;
        }
        .event-group-off {
            left: 45% !important;
            width: 3% !important;
            background-color: {{ $colors['stateColors']['group_off'] }} !important;
        }
        /* .event-group-missing removed: missing folded into Off for grouped view */
        .ec-sidebar .ec-time:first-of-type {
            margin-top: 8px;
        }
        .ec-toolbar .ec-center {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0 12px;
        }
        #summary {
            max-width: 100%;
        }
    </style>

    <!-- include the header blade file -->
    @include('pulse::iot-insights.pith.header')

    {{-- Summary strip rendered here first, then moved into the toolbar center by JS --}}
    <div id="summary" style="display:none;"></div>

    <div class="px-4 pb-6">
        <div class="bg-white rounded-2xl shadow-xl border border-gray-100">
            <div class="rounded-t-2xl border-b border-gray-100 bg-gradient-to-r from-gray-50 to-white px-6 py-4">
                <div class="flex items-center justify-between flex-wrap gap-4">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-800">Weekly Production Schedule</h2>
                        <p class="text-sm text-gray-500 mt-0.5">Real-time pith CFT tracking</p>
                    </div>

                    {{-- GROUPED legend (default, shown when toggle is Off) --}}
                    <div class="flex items-center gap-3 text-sm flex-wrap" id="legend-grouped">
                        <div class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg border"
                             style="background: {{ $colors['stateColors']['group_on'] }}10; border-color: {{ $colors['stateColors']['group_on'] }}30;">
                            <div class="w-3 h-3 rounded" style="background: {{ $colors['stateColors']['group_on'] }};"></div>
                            <span class="font-medium" style="color: {{ $colors['stateColors']['group_on'] }};">On</span>
                        </div>
                        <div class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-gray-50 border border-gray-300">
                            <div class="w-3 h-3 rounded" style="background: {{ $colors['stateColors']['group_off'] }};"></div>
                            <span class="font-medium text-gray-900">Off</span>
                        </div>
                        <!-- Missing legend removed: missing durations counted within Off -->
                    </div>

                    {{-- DETAILED legend (shown when toggle is On / "All") --}}
                    <div class="flex items-center gap-3 text-sm flex-wrap hidden" id="legend-detailed">
                        <div class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg border"
                             style="background: {{ $colors['stateColors']['efficient'] }}10; border-color: {{ $colors['stateColors']['efficient'] }}30;">
                            <div class="w-3 h-3 rounded" style="background: {{ $colors['stateColors']['efficient'] }};"></div>
                            <span class="font-medium" style="color: {{ $colors['stateColors']['efficient'] }};">Efficient</span>
                        </div>
                        <div class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg border" style="background: {{ $colors['stateColors']['idle'] }}10; border-color: {{ $colors['stateColors']['idle'] }}30;">
                            <div class="w-3 h-3 rounded" style="background: {{ $colors['stateColors']['idle'] }};"></div>
                            <span class="font-medium" style="color: {{ $colors['stateColors']['idle'] }};">Idle</span>
                        </div>
                        <div class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg border" style="background: {{ $colors['stateColors']['overload'] }}10; border-color: {{ $colors['stateColors']['overload'] }}30;">
                            <div class="w-3 h-3 rounded" style="background: {{ $colors['stateColors']['overload'] }};"></div>
                            <span class="font-medium" style="color: {{ $colors['stateColors']['overload'] }};">Overloaded</span>
                        </div>
                        <div class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-gray-50 border border-gray-200">
                            <div class="w-3 h-3 rounded" style="background: {{ $colors['stateColors']['off'] }};"></div>
                            <span class="font-medium text-gray-700">Off</span>
                        </div>
                        <div class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-gray-50 border border-gray-300">
                            <div class="w-3 h-3 rounded" style="background: {{ $colors['stateColors']['shutdown'] }};"></div>
                            <span class="font-medium text-gray-900">Shutdown</span>
                        </div>
                        <div class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-gray-50 border border-gray-200">
                            <div class="w-3 h-3 rounded" style="background: {{ $colors['stateColors']['missing'] }};"></div>
                            <span class="font-medium text-gray-600">Data Missing</span>
                        </div>
                    </div>

                    {{-- Toggle: On/Off (grouped) <-> All (detailed) --}}
                    <div class="flex items-center gap-2">
                        <span class="text-xs text-gray-500 font-medium">On/Off</span>
                        <button id="view-toggle"
                                onclick="toggleChartView()"
                                class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors duration-200 focus:outline-hidden bg-gray-300">
                            <span id="toggle-knob"
                                  class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform duration-200 translate-x-1">
                            </span>
                        </button>
                        <span class="text-xs text-gray-500 font-medium">All States</span>
                    </div>

                </div>
            </div>

            <div id="calendar" class="w-full" wire:ignore></div>
        </div>
    </div>

    <script src="@assetVersion('js/iot-details-popover.js')"></script>
    <script>
        // Pass the shift colors, pith total color and states colors from your config file to JavaScript
        const shiftColors = @json([
            config('colors.shift.1'),
            config('colors.shift.2'),
            config('colors.shift.3')
        ]);

        // ── Module-scope toggle state ─────────────────────────────────────────────────
        // false = grouped (On/Off), true = detailed (all 6 states). The day cards carry
        // both renderings in their markup, so this only drives which one CSS shows plus
        // the lane class on the time-grid events below.
        let showDetailedView  = false;

        // Every CSS lane class that can ever be applied to a time-grid event element.
        // Keeping this as a single list lets applyStateClass() remove all of them in one
        // pass before adding the correct one, so switching views can never leave a stale
        // class — and therefore a stale color/position — behind on the same element.
        const ALL_STATE_LANE_CLASSES = [
            'event-efficient', 'event-idle', 'event-overload',
            'event-off',       'event-shutdown', 'event-missing',
            'event-group-on',  'event-group-off', 'event-group-missing',
        ];


        //   frontend never re-derives which raw states count as "on" vs "off" — that
        //   mapping lives in exactly one place: IotPithController::STATE_GROUPS.
        //   Live events (isLive=true) always use the grouped ON/OFF lane even when
        //   showDetailedView is active — scheduler hasn't processed today yet.
        function stateLaneClass(state, group, isLive) {
            if (showDetailedView && !isLive) {
                switch (state) {
                    case 'efficient': return 'event-efficient';
                    case 'idle':      return 'event-idle';
                    case 'overload':  return 'event-overload';
                    case 'off':       return 'event-off';
                    case 'shutdown':  return 'event-shutdown';
                    case 'missing':   return 'event-missing';
                    default:          return '';
                }
            }

            switch (group) {
                case 'on':      return 'event-group-on';
                case 'off':     return 'event-group-off';
                case 'missing': return 'event-group-missing';
                default:        return '';
            }
        }

        // ── Helper: group key → display label (used in tooltip when grouped) ──────────
        function groupLabel(group) {
            switch (group) {
                case 'on':      return 'On';
                case 'off':     return 'Off';
                case 'missing': return 'Missing';
                default:        return group || '';
            }
        }

        // ── Helper: apply the correct lane class to one time-grid element ─────────────
        function applyStateClass(el, state, group) {
            const isLive = el.getAttribute('data-is-live') === '1';
            ALL_STATE_LANE_CLASSES.forEach(cls => el.classList.remove(cls));
            const laneClass = stateLaneClass(state, group, isLive);
            if (laneClass) {
                el.classList.add(laneClass);
            }
        }

        // ── Helper: write/rewrite tooltip text for the current view mode ──────────────
        // Reads cached data-* attributes set in eventDidMount so moment() is never called
        // again here — the formatted strings are already on the element.
        function applyTooltipContent(el) {
            const start = el.getAttribute('data-start-label');
            const end   = el.getAttribute('data-end-label');
            if (start === null || end === null) {
                return;
            }
            const state        = el.getAttribute('data-state');
            const group        = el.getAttribute('data-group');
            const isLive       = el.getAttribute('data-is-live') === '1';
            // Live events always show the grouped label (On/Off) regardless of view mode.
            const displayState = (showDetailedView && !isLive) ? state : groupLabel(group);
            el.setAttribute(
                'data-tooltip-content',
                `State: ${displayState}<br>Start: ${start}<br>End: ${end}`
            );
        }

        // ── Helper: restyle every already-rendered time-grid event for the current mode
        // Uses the .has-tooltip selector (set only on time-grid events in eventDidMount,
        // never on allDay wrappers) so this loop can never accidentally touch allDay
        // elements even if future code adds data-state to them for other reasons.
        function restyleTimeGridEvents() {
            document.querySelectorAll('#calendar [data-state].has-tooltip').forEach(function (el) {
                const state = el.getAttribute('data-state');
                const group = el.getAttribute('data-group');
                applyStateClass(el, state, group);
                applyTooltipContent(el);
            });
        }

        // ── Toggle handler ────────────────────────────────────────────────────────────
        // Must be module-scope so the button's onclick="toggleChartView()" can reach it.
        function toggleChartView() {
            if (window.IotDetailsPopover) {
                window.IotDetailsPopover.unpin();
            }
            showDetailedView = !showDetailedView;

            const btn  = document.getElementById('view-toggle');
            const knob = document.getElementById('toggle-knob');

            if (showDetailedView) {
                btn.classList.replace('bg-gray-300', 'bg-blue-500');
                knob.classList.replace('translate-x-1', 'translate-x-6');
            } else {
                btn.classList.replace('bg-blue-500', 'bg-gray-300');
                knob.classList.replace('translate-x-6', 'translate-x-1');
            }

            document.getElementById('legend-grouped').classList.toggle('hidden',  showDetailedView);
            document.getElementById('legend-detailed').classList.toggle('hidden', !showDetailedView);

            // The day cards ship both rails and the readout in every payload, so
            // switching views is a repaint: flip one class and CSS decides what is
            // visible. Nothing is refetched and nothing is re-measured, which is why
            // grouped and detailed can never drift apart — they are the same markup.
            document.getElementById('calendar').classList.toggle('pd-detailed', showDetailedView);

            // Restyle already-rendered time-grid state lines instantly — no refetch needed.
            restyleTimeGridEvents();
        }

        // ── Calendar initialisation ───────────────────────────────────────────────────
        document.addEventListener('DOMContentLoaded', function () {
            const showActuals = {{ auth()->user()->currentTeam->showActuals() ? 'true' : 'false' }};
            var calendarEl    = document.getElementById('calendar');
            var slotDuration  = '00:15:00';

            let currentStartDate = moment().startOf('week').format('YYYY-MM-DD');
            let currentEndDate = moment().endOf('week').format('YYYY-MM-DD');
            var activeFormat = 'shift_date';

            var slotMinTime = "{{ $shiftStartTime }}";
            var slotMaxTime = calculateSlotMaxTime(slotMinTime, 24);

            var calendar = EventCalendar.create(calendarEl, {
                view: 'timeGridWeek',
                firstDay: 1,
                allDaySlot: true,
                allDayContent: { html: '<span class="caps-label">Day</span>' },
                validRange: {
                    end: '{{ $today }}'
                },
                slotMinTime: slotMinTime,
                slotMaxTime: slotMaxTime,
                slotDuration: slotDuration,
                slotLabelInterval: 0,
                locale: 'en-IN',
                headerToolbar: {
                    start: 'prev, datePickerTitle, next',
                    center: '',
                    end: 'mins1,mins5,mins15,mins30,hr1,hr2'
                },
                customButtons: {
                    datePickerTitle: { text: '' },
                    mins1:  { text: '1m', click: function() { setSlotDuration('00:01:00', 'mins1'); } },
                    mins5:  { text: '5m', click: function() { setSlotDuration('00:05:00', 'mins5'); } },
                    mins15: { text: '15m', click: function() { setSlotDuration('00:15:00', 'mins15'); }, active: true },
                    mins30: { text: '30m', click: function() { setSlotDuration('00:30:00', 'mins30'); } },
                    hr1:    { text: '1hr', click: function() { setSlotDuration('01:00:00', 'hr1'); } },
                    hr2:    { text: '2hr', click: function() { setSlotDuration('02:00:00', 'hr2'); } },
                    calendarDate: {
                        text: 'Calendar Date',
                        click: function () {
                            setActiveButton('calendarDate', 'shiftDate');
                            activeFormat = 'date';
                            reloadEvents();
                        }
                    },
                    shiftDate: {
                        text: 'Shift Date',
                        click: function () {
                            setActiveButton('shiftDate', 'calendarDate');
                            activeFormat = 'shift_date';
                            reloadEvents();
                        },
                        active: true
                    },
                },
                dayHeaderFormat: function (date) {
                    const dayName = moment(date).format('ddd');
                    const dayDate = moment(date).format('D MMM');

                    const formattedDate = moment(date).format('YYYY-MM-DD');
                    const todayStr = '{{ now()->toDateString() }}';
                    const isToday  = (formattedDate === todayStr);

                    const badge = isToday
                        ? `<span style="
                        display:inline-flex;
                        align-items:center;
                        gap:3px;
                        margin-left:4px;
                        padding:1px 6px 1px 5px;
                        font-size:12px;
                        font-weight:600;
                        background:#22c55e;
                        color:#fff;
                        border-radius:99px;
                        vertical-align:middle;
                        line-height:16px;">
                        <span style="width:5px;height:5px;border-radius:50%;background:#fff;display:inline-block;"></span>Live
                    </span>`
                        : '';

                    return { html: `<span class="inline-flex items-baseline gap-1.5"><span class="caps-label">${dayName}</span><span class="text-sm font-semibold normal-case tabular-nums text-gray-900">${dayDate}</span>${badge}</span>` };
                },
                editable: false,
                eventStartEditable: false,
                eventDurationEditable: false,
                eventSources: [
                    {
                        // NOTE: this must stay a plain (non-async) function. The library checks
                        // whether the return value of this function is `undefined`; if not, it
                        // *also* resolves that return value and feeds it to successCallback. An
                        // `async function` always returns a Promise (never undefined), so on top
                        // of our own successCallback(...) call below it would also fire
                        // successCallback(undefined) once the async work finished, crashing the
                        // library's internal event mapping. Do the async work in an inner IIFE
                        // instead so this outer function itself returns nothing.
                        events: function(fetchInfo, successCallback, failureCallback) {
                            (async () => {
                            let startDate = new Date(fetchInfo.startStr);
                            let startStr = startDate.getFullYear() + "-"
                                            + (startDate.getMonth() + 1).toString().padStart(2, '0') + "-"
                                            + startDate.getDate().toString().padStart(2, '0');

                            let endDate = new Date(fetchInfo.endStr);
                            endDate.setDate(endDate.getDate() - 1); // Subtract one day
                            let endStr = endDate.getFullYear() + "-"
                                            + (endDate.getMonth() + 1).toString().padStart(2, '0') + "-"
                                            + endDate.getDate().toString().padStart(2, '0');

                            try {
                                // The day cards now arrive fully built inside the all-day
                                // events, machine rails included, so this is a single
                                // request — fetch-summary is no longer needed here.
                                const eventsResponse = await fetch(
                                    `/{{ $teamId }}/iot-pith-loads/fetch-events?start=${startStr}&end=${endStr}&format=${activeFormat}`
                                ).then(r => r.json());

                                // Render top summary strip first
                                updateSummary(eventsResponse.totals);

                                // Separate events and render daily production cards
                                const badges = eventsResponse.events.filter(e => e.allDay);
                                const states = eventsResponse.events.filter(e => !e.allDay && !e.extendedProps?.isLive);
                                const liveData = eventsResponse.events.filter(e => !e.allDay && e.extendedProps?.isLive);

                                // Render the badges and past states
                                successCallback([...badges, ...states]);

                                // 4. Render the live (today's) data last
                                if (liveData.length > 0) {
                                    setTimeout(() => {
                                        liveData.forEach(event => calendar.addEvent(event));
                                    }, 50);
                                }
                            } catch (error) {
                                console.error(error);
                                if (window.toast) {
                                    window.toast.error('Failed to load events data');
                                } else {
                                    alert('There was an error while fetching events!');
                                }
                                failureCallback();
                            }
                            })();
                        }
                    }
                ],
                eventDidMount: function(info) {
                    // Add custom class based on state for each event
                    const state = info.event.extendedProps.state;
                    const group = info.event.extendedProps.group;
                    const isLive = info.event.extendedProps.isLive || false;

                    // Stash raw state + server-computed group + live flag on the element so
                    // the toggle handler can re-derive the correct lane class and tooltip
                    // without needing the calendar's event objects again.
                    if (state) { info.el.setAttribute('data-state', state); }
                    if (group) { info.el.setAttribute('data-group', group); }
                    if (isLive) { info.el.setAttribute('data-is-live', '1');   }

                    applyStateClass(info.el, state, group);

                    // Add tooltip for state lines
                    if (!info.event.allDay) {
                        const startTime = moment(info.event.start).format('h:mm a');
                        const endTime   = moment(info.event.end).format('h:mm a');
                        info.el.setAttribute('data-start-label', startTime);
                        info.el.setAttribute('data-end-label',   endTime);
                        applyTooltipContent(info.el);
                        // has-tooltip is the discriminator used by restyleTimeGridEvents()
                        // to skip allDay wrappers — only set it here, never on allDay events.
                        info.el.classList.add('has-tooltip');
                    } else if (window.IotDetailsPopover && info.event.extendedProps && info.event.extendedProps.date) {
                        window.IotDetailsPopover.attach(info.el, info.event.extendedProps);
                    }
                },
                eventContent: function(info) {
                    if (info.event.allDay) {
                        // Content for all-day events
                        return {
                            html: `
                                <div class="all-day-event">
                                    <span class="all-day-event-title">${info.event.title}</span>
                                </div>
                            `
                        };
                    } else {
                        // Empty content for individual (time-bound) events
                        return { html: '' };
                    }
                },
                eventMouseEnter: function(info) {
                    if (info.event.allDay) return;

                    const tooltipContent = info.el.getAttribute('data-tooltip-content');
                    const tooltip = document.createElement('div');
                    tooltip.className = 'event-tooltip';
                    tooltip.innerHTML = tooltipContent;
                    document.body.appendChild(tooltip);

                    // Pre-render for accurate dimensions
                    tooltip.style.visibility = 'hidden';
                    tooltip.style.display = 'block';

                    const moveTooltip = (e) => {
                        const isSunday = moment(info.event.start).day() === 0;
                        const rect = tooltip.getBoundingClientRect();

                        let x = e.pageX + 10;
                        let y = e.pageY + 10;

                        if (isSunday) {
                            if (x + rect.width > window.innerWidth) x = window.innerWidth - rect.width - 10;
                            if (y + rect.height > window.innerHeight) y = e.pageY - rect.height - 10;
                            if (x < 0) x = 10;
                            if (y < 0) y = 10;
                        }

                        tooltip.style.left = `${x}px`;
                        tooltip.style.top = `${y}px`;
                        tooltip.style.visibility = 'visible';
                    };

                    moveTooltip(info.jsEvent);
                    info.el.addEventListener('mousemove', moveTooltip);
                    info.el._tooltip = tooltip;
                    info.el._moveHandler = moveTooltip;
                },

                eventMouseLeave: function(info) {
                    if (!info.el._tooltip) return;

                    info.el.removeEventListener('mousemove', info.el._moveHandler);
                    info.el._tooltip.remove();
                    delete info.el._tooltip;
                    delete info.el._moveHandler;
                },
                datesSet: function(info) {
                    if (window.IotDetailsPopover) {
                        window.IotDetailsPopover.unpin();
                    }
                    // Update custom button text
                    const titleButton = document.querySelector('.ec-datePickerTitle'); // Find the custom button
                    if (titleButton) {
                       titleButton.textContent = info.view.title;
                    }

                    currentStartDate = moment(info.startStr).format('YYYY-MM-DD');
                    currentEndDate = moment(info.endStr).subtract(1, 'days').format('YYYY-MM-DD');
                },
                nowIndicator: false,
                selectable: false
            });

            // First pass before any week has loaded, so the day-name row is pinned at the
            // right offset even if the events request is slow or fails.
            setTimeout(syncStickyHeaderOffset, 100);

            $('.ec-toolbar .ec-datePickerTitle').daterangepicker({
                singleDatePicker: true,
                locale: {
                    firstDay: 1,
                },
                showDropdowns: true,
                autoApply: true,
                maxDate: moment(),
            }).on('apply.daterangepicker', function (ev, picker) {
                calendar.setOption('date', picker.startDate.toDate());
            });

            // Utility function to set the active state of custom buttons
            function setActiveButton(activeButton, inactiveButton) {
                $(`.ec-${inactiveButton}`).removeClass('ec-active');
                $(`.ec-${activeButton}`).addClass('ec-active');
            }

            // Function to reload events based on the active format
            function reloadEvents() {
                if (window.IotDetailsPopover) {
                    window.IotDetailsPopover.unpin();
                }
                if (activeFormat === 'shift_date') {
                    calendar.setOption('slotMinTime', '{{ $shiftStartTime }}');
                    calendar.setOption('slotMaxTime', calculateSlotMaxTime('{{ $shiftStartTime }}'));
                } else {
                    calendar.setOption('slotMinTime', '00:00:00');
                    calendar.setOption('slotMaxTime', calculateSlotMaxTime('00:00:00', 24));
                }
                calendar.refetchEvents();
            }

            // Update summary section dynamically
            function updateSummary(totals) {
                const summaryContainer = document.getElementById('summary');
                summaryContainer.innerHTML = '';
                summaryContainer.style.display = 'flex';
                summaryContainer.style.alignItems = 'center';

                const entries = Object.entries(totals || {});
                const showTotal = entries.length > 1;

                const strip = document.createElement('div');
                strip.style.cssText = `display:inline-flex; align-items:stretch; background:white; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.06); max-width:100%;`;

                // ── No shift data ─────────────────────────────────────────────────────────
                if (entries.length === 0) {
                    strip.innerHTML = `
                        <div style="display:flex; align-items:center; gap:10px; padding:6px 14px;">
                            <div style="width:28px; height:28px; border-radius:7px; background:#f3f4f6; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                                <svg width="16" height="16" fill="none" stroke="#9ca3af" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                                    <path d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/>
                                </svg>
                            </div>
                            <div>
                                <div style="font-size:12px; color:#374151; font-weight:500; font-family:inherit;">Pith Production</div>
                                <div style="font-size:16px; font-weight:600; color:#d1d5db; line-height:1.2; font-family:inherit;">— <span style="font-size:12px; font-weight:400;">No shift data</span></div>
                            </div>
                        </div>`;
                    summaryContainer.appendChild(strip);
                    injectSummaryIntoToolbar();
                    return;
                }

                // ── Shift cells ───────────────────────────────────────────────────────────
                let totalLoadsExpected = 0;
                let totalLoadsProduced = 0;

                entries.forEach(([shiftName, data], index) => {
                    totalLoadsExpected += data.loads_expected;
                    totalLoadsProduced += data.loads_produced;

                    const borderColor = shiftColors[index % 3];

                    if (index > 0) {
                        const sep = document.createElement('div');
                        sep.style.cssText = 'width:1px; background:#e5e7eb; flex-shrink:0;';
                        strip.appendChild(sep);
                    }

                    const cell = document.createElement('div');
                    cell.style.cssText = 'display:flex; align-items:center; gap:10px; padding:6px 14px; min-width:0; white-space:nowrap;';
                    cell.innerHTML = `
                        <div style="width:28px; height:28px; border-radius:7px; background:${borderColor}18; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                            <svg width="16" height="16" fill="none" stroke="${borderColor}" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                                <path d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/>
                            </svg>
                        </div>
                        <div style="min-width:0;">
                            <div style="font-size:12px; color:#374151; font-weight:500; font-family:inherit;">${shiftName}</div>
                            <div style="font-size:16px; font-weight:600; color:${borderColor}; line-height:1.2; font-family:inherit;">
                                ${(!showActuals || data.loads_produced == 0) ? `${formatToIndianNumber(Number(data.loads_expected ?? 0))} <span style="font-size:12px; color:#6b7280; font-weight:400;">est</span>` : `<span class="counter-value">0</span><span style="font-size:12px; color:#6b7280; font-weight:400;"> / ${formatToIndianNumber(Number(data.loads_expected ?? 0))} est</span>`}
                            </div>
                        </div>`;

                    strip.appendChild(cell);

                    setTimeout(() => {
                        const span = cell.querySelector('.counter-value');
                        if (span) animateValue(span, 0, data.loads_produced, 800);
                    }, index * 100);
                });

                // ── Total cell (only when 2+ shifts) ─────────────────────────────────────
                if (showTotal) {
                    const sep = document.createElement('div');
                    sep.style.cssText = 'width:1px; background:#e5e7eb; flex-shrink:0;';
                    strip.appendChild(sep);

                    const totalCell = document.createElement('div');
                    totalCell.style.cssText = 'display:flex; align-items:center; gap:10px; padding:6px 14px; background:#f9fafb; min-width:0; white-space:nowrap;';
                    totalCell.innerHTML = `
                        <div style="width:28px; height:28px; border-radius:7px; background:#f3f4f6; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                            <svg width="16" height="16" fill="none" stroke="#374151" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                                <path d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                            </svg>
                        </div>
                        <div style="min-width:0;">
                            <div style="font-size:12px; color:#374151; font-weight:500; font-family:inherit;">Total</div>
                            <div style="font-size:16px; font-weight:600; color:#111827; line-height:1.2; font-family:inherit;">
                                ${(!showActuals || totalLoadsProduced == 0) ? `${formatToIndianNumber(Number(totalLoadsExpected ?? 0))} <span style="font-size:12px; color:#6b7280; font-weight:400;">est</span>` : `<span class="counter-value">0</span><span style="font-size:12px; color:#6b7280; font-weight:400;"> / ${formatToIndianNumber(Number(totalLoadsExpected ?? 0))} est</span>`}
                            </div>
                        </div>`;

                    strip.appendChild(totalCell);

                    setTimeout(() => {
                        const span = totalCell.querySelector('.counter-value');
                        if (span) animateValue(span, 0, totalLoadsProduced, 1000);
                    }, entries.length * 100);
                }

                summaryContainer.appendChild(strip);
                injectSummaryIntoToolbar();
            }

            function injectSummaryIntoToolbar() {
                const toolbarCenter = calendarEl.querySelector('.ec-toolbar .ec-center');
                if (toolbarCenter) {
                    const summaryEl = document.getElementById('summary');
                    if (summaryEl && !toolbarCenter.contains(summaryEl)) {
                        toolbarCenter.innerHTML = '';
                        toolbarCenter.appendChild(summaryEl);
                    }
                }
                syncStickyHeaderOffset();
            }

            // Where the pinned day-name row has to sit: directly under the pinned
            // toolbar, which is itself pinned under the app's 50px navigation. Measured
            // because the toolbar's height depends on what it is carrying and on how
            // narrow the window is.
            function syncStickyHeaderOffset() {
                const toolbar = calendarEl.querySelector('.ec-toolbar');
                if (!toolbar) {
                    return;
                }
                calendarEl.style.setProperty('--ec-header-top', `${50 + toolbar.offsetHeight}px`);
            }

            window.addEventListener('resize', syncStickyHeaderOffset);

            function animateValue(element, start, end, duration) {
                if (!element) return;
                const range = end - start;
                const increment = range / (duration / 16);
                let current = start;

                const timer = setInterval(() => {
                    current += increment;
                    if ((increment > 0 && current >= end) || (increment < 0 && current <= end)) {
                        current = end;
                        clearInterval(timer);
                    }
                    element.textContent = formatToIndianNumber(Math.floor(current));
                }, 16);
            }

            // Function to set slot duration and reload calendar
            function setSlotDuration(duration, activeButton) {
                if (window.IotDetailsPopover) {
                    window.IotDetailsPopover.unpin();
                }
                calendar.setOption('slotDuration', duration);
                $('.ec-mins1, .ec-mins5, .ec-mins10, .ec-mins15, .ec-mins30, .ec-hr1, .ec-hr2').removeClass('ec-active');
                $(`.ec-${activeButton}`).addClass('ec-active'); // Add active state to the clicked button
            }

        });

        function number_format(number) {
            return formatNumber(number);
        }
    </script>
</x-app-layout>
