<x-app-layout>

    <link rel="stylesheet" href="@assetVersion('css/daterangepicker.css')">
    <link rel="stylesheet" href="@assetVersion('css/date-range-selector.css')">

    <x-slot name="header">
        {{-- The header is sticky, so the rule marks where the filters end and the scrolling report begins. --}}
        <div class="flex flex-col w-full pb-2 border-b border-gray-300">
            <div class="flex flex-wrap items-center justify-between gap-x-6 gap-y-3 w-full">
                <div class="flex items-center gap-4">
                    <div class="relative">
                        <div class="absolute inset-0 rounded-xl blur-lg opacity-30" style="background: linear-gradient(135deg, {{ config('colors.pith.total') }}, {{ config('colors.shift.2') }});"></div>
                        <div class="relative p-3 rounded-xl shadow-lg" style="background: linear-gradient(135deg, {{ config('colors.pith.total') }}, {{ config('colors.shift.2') }});">
                            <x-menu-icon name="dashboard" :size="28" class="text-white" />
                        </div>
                    </div>
                    <div>
                        <h1 class="text-2xl font-semibold tracking-tight text-gray-900">Dashboard</h1>
                        <p class="text-sm text-gray-500 mt-0.5">Real-time factory performance overview</p>
                    </div>
                </div>

                <!-- Period selector -->
                <div x-data="{ init() { initRangeSelector(); } }" class="flex flex-col items-end gap-2 grow min-w-0">
                    {{-- Last Year is reachable through Custom; it is left out so the bar still fits
                         beside the title at 1366px, the narrowest screen this runs on. --}}
                    @include('pulse::dashboard.date-range-selector', [
                        'presets' => [
                            'today' => 'Today',
                            'this_month' => 'This Month',
                            'this_year' => 'This Year',
                            'yesterday' => 'Yesterday',
                            'last_month' => 'Last Month',
                            'custom' => 'Custom',
                        ],
                        'default' => 'yesterday',
                        // One day here means the hourly, per-shift view the old Day tab showed.
                        'dayType' => 'day',
                    ])

                    {{-- Superadmin only, and only while yesterday is the selected period —
                         the rerun acts on yesterday whatever else is on screen. --}}
                    @if (Auth::user()->isSuperadmin() && now()->gt($shiftStartWithBuffer) && ! app()->environment(['demo', 'local']))
                        <button type="button" id="runSchedulerBtn"
                            class="pulse-yesterday-only hidden h-9 whitespace-nowrap bg-blue-500 hover:bg-blue-400 text-white text-sm font-semibold px-3 rounded">
                            Rerun Yesterday Data
                        </button>
                    @endif
                </div>
            </div>

            @if (now()->lte($shiftStartWithBuffer))
                <div id="yesterdaySummaryMsg" class="mt-1 w-full text-center bg-blue-100 text-blue-800 px-2 py-1 rounded shadow-xs hidden">
                    Please wait until {{ \Carbon\Carbon::parse($shiftStartWithBuffer)->format('g:i a') }} to get yesterday's summary.
                </div>
            @endif
        </div>
    </x-slot>

    @php
        /*
         * The tiles carry the period total as the headline and each shift's share as a chip
         * beneath it, matching the fibre dashboard.
         *
         * Only metrics the summary table actually records are shown: punches are deliberately
         * absent, since `shift_punch` is never populated outside the day view and a tile that
         * reads zero for every other period would be worse than no tile.
         *
         * The bags cube is the red one because that is the colour its bars carry —
         * growbags_charts.bags.shift1 is COLOR_PRIMARY. It wore the orange cube before, which is
         * COLOR_SECONDARY, i.e. shift 2's colour, so the tile and the chart under it disagreed.
         */
        $summaryTiles = [
            ['metric' => 'bags', 'id' => 'kpiBagsProduced', 'label' => 'Bags Produced', 'icon' => 'fibre-custom-icon.png'],
            ['metric' => 'eb', 'id' => 'kpiEbCost', 'label' => 'EB Cost', 'icon' => 'line-icon.png'],
        ];

        $machineColors = array_values(config('colors.growbags_charts.machines'));
        $hasMachineChart = $machines->count() > 1;
    @endphp

    <div class="w-full py-3 ps-2 sm:ps-3 lg:ps-4 pe-3">

        <div id="dashboard-error"
            class="hidden mb-3 p-3 rounded-xl border border-red-200 bg-red-50 text-sm text-red-800 flex items-center gap-2">
            <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
            <span id="dashboard-error-message"></span>
        </div>

        {{-- Four columns for two tiles: a tile stays a quarter of the row, the width the fibre
             dashboard gives its five, rather than stretching to half the page each. --}}
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
            @foreach ($summaryTiles as $tile)
                <x-dashboard.kpi :label="$tile['label']" :value-id="$tile['id']" :icon="$tile['icon']">
                    @if ($shifts->count() > 1)
                        <div class="shrink-0 flex flex-col items-end gap-1">
                            @foreach ($shifts as $shiftId => $shiftName)
                                @php $shiftColour = config('colors.shift.'.($loop->index % 3 + 1)); @endphp
                                {{-- Tinted from the shift's own colour, the one its bars use in the charts below --}}
                                <span class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-xs leading-tight whitespace-nowrap"
                                    style="color: {{ $shiftColour }}; background-color: {{ $shiftColour }}14;">
                                    <span class="font-medium">{{ $shiftName }}:</span>
                                    <span class="font-semibold" data-shift-metric="{{ $tile['metric'] }}" data-shift="{{ $shiftId }}">&mdash;</span>
                                </span>
                            @endforeach
                        </div>
                    @endif
                </x-dashboard.kpi>
            @endforeach
        </div>

        <!-- Bags — the headline metric, given the full width -->
        <div class="mb-4">
            <x-dashboard.card title="Growbags Production" title-id="bagsChartTitle" empty-id="bagsChartEmpty">
                <div id="bagsChart" class="w-full"></div>

                {{-- Day view with no raw device rows: the hourly grain does not exist, so the
                     day's own figures stand in for the chart rather than an empty frame. --}}
                <div id="bagsDaySummary" class="hidden grid grid-cols-1 md:grid-cols-3 gap-4 pb-4">
                    <div class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3">
                        <p class="caps-label">Total Bags Produced</p>
                        <p id="daySummaryBags" class="text-2xl font-semibold tabular-nums tracking-tight text-gray-900 mt-1">&mdash;</p>
                    </div>
                    <div class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3">
                        <p class="caps-label">EB Units</p>
                        <p id="daySummaryEbUnits" class="text-2xl font-semibold tabular-nums tracking-tight text-gray-900 mt-1">&mdash;</p>
                    </div>
                    <div class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3">
                        <p class="caps-label">EB Value</p>
                        <p id="daySummaryEbPrice" class="text-2xl font-semibold tabular-nums tracking-tight text-gray-900 mt-1">&mdash;</p>
                    </div>
                </div>
            </x-dashboard.card>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
            <x-dashboard.card title="EB Cost" title-id="ebChartTitle" empty-id="ebChartEmpty"
                class="{{ $hasMachineChart ? '' : 'lg:col-span-2' }}">
                <div id="ebChart" class="w-full"></div>
            </x-dashboard.card>

            @if ($hasMachineChart)
                <x-dashboard.card title="Growbags by Machines" title-id="machineChartTitle" empty-id="machineChartEmpty">
                    <x-slot name="meta">
                        <div class="flex items-center gap-4 flex-wrap" id="machineCheckboxes">
                            @foreach ($machines as $i => $machine)
                                <label class="flex items-center gap-1 cursor-pointer">
                                    <input type="checkbox" class="machine-toggle" value="{{ $machine->id }}"
                                        data-name="{{ $machine->machine_name }}"
                                        data-color="{{ $machineColors[$i % count($machineColors)] }}"
                                        checked
                                        onchange="toggleMachineLine(this)">
                                    <span style="color:{{ $machineColors[$i % count($machineColors)] }}">
                                        {{ $machine->machine_name }}
                                        <span class="text-gray-500">(&times;{{ $machine->bags_per_punch ?? 0 }} bags/punch)</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </x-slot>

                    <div id="lineChart" class="w-full"></div>
                </x-dashboard.card>
            @endif
        </div>

        {{-- Machine × shift breakdown. With a single machine its only row repeats the tiles
             above it, so the table earns its space only once there are machines to compare. --}}
        @if ($hasMachineChart)
            <div class="mb-4">
                <x-dashboard.card title="Machine Summary" empty-id="summaryTableEmpty">
                    <div id="summaryTableContainer" class="w-full overflow-x-auto pb-4"></div>
                </x-dashboard.card>
            </div>
        @endif

    </div>

    <!-- Loading Overlay -->
    <div id="loading-overlay" class="hidden fixed inset-0 bg-white/60 flex items-center justify-center z-50">
        <img src="{{ asset('images/loading-icon.png') }}" alt="Loading" class="w-12 h-12 animate-spin">
    </div>

    <script src="@assetVersion('js/daterangeselector.js')"></script>
    <script>
        const growbagsChartColors = @json(config('colors.growbags_charts'));
        const machineLineColors = Object.values(growbagsChartColors.machines);

        // metric key → the id of the tile showing its period total. Built from the same array
        // that renders the tiles, so adding one there cannot leave the script behind.
        const summaryTiles = @json(collect($summaryTiles)->pluck('id', 'metric'));

        let bagsChart         = null;
        let ebChart           = null;
        let lineChartInstance = null;

        function showLoader() {
            $('#loading-overlay').removeClass('hidden');
        }

        function hideLoader() {
            $('#loading-overlay').addClass('hidden');
        }

        function showDashboardError(message) {
            $('#dashboard-error-message').text(message);
            $('#dashboard-error').removeClass('hidden');
        }

        function clearDashboardError() {
            $('#dashboard-error').addClass('hidden');
        }

        function fetchDashboardData(start, end, type = 'month') {
            if (!start || typeof start.format !== 'function') {
                console.warn('fetchDashboardData: invalid start date', start);
                return;
            }

            $.ajax({
                url: '/{{ $teamId }}/dashboard/growbags',
                method: 'GET',
                data: {
                    start: start.format('YYYY-MM-DD'),
                    end:   end.format('YYYY-MM-DD'),
                    type:  type
                },
                beforeSend: function () {
                    clearDashboardError();
                    showLoader();
                },
                success: function (response) {
                    renderCharts(response.data.growbagsProduction, type);
                },
                error: function (xhr) {
                    console.error('Error fetching dashboard data:', xhr);
                    showDashboardError('Could not load the dashboard for the selected period. Please try again.');
                },
                complete: function () {
                    hideLoader();
                }
            });
        }

        /**
         * Swap a chart mount for its card's empty message. Every card owns both, so a period
         * with no production reads as "no data" instead of an axis-only frame.
         */
        function toggleChartEmpty(chartId, emptyId, hasData) {
            const chartEl = document.getElementById(chartId);
            const emptyEl = document.getElementById(emptyId);

            if (chartEl) {
                chartEl.style.display = hasData ? '' : 'none';
                if (! hasData) {
                    chartEl.innerHTML = '';
                }
            }

            if (emptyEl) {
                emptyEl.classList.toggle('hidden', hasData);
            }
        }

        function setCardTitle(titleId, text) {
            const el = document.getElementById(titleId);
            if (el) {
                el.textContent = text;
            }
        }

        /** Pass null when the figure cannot be computed — the tile falls back to its em dash. */
        function setKpi(id, html) {
            const el = document.getElementById(id);
            if (el) {
                el.innerHTML = html ?? '&mdash;';
            }
        }

        /**
         * Pick an axis maximum that divides evenly into `ticks` steps, plus the number of
         * decimals those steps need.
         *
         * Rounding the max up to a power of ten and leaving ApexCharts to divide it by the tick
         * count produces fractional ticks that `decimalsInFloat: 0` then rounds to duplicate
         * labels. Choosing the step first and deriving the max from it keeps every gridline on
         * a round number.
         *
         * @param {number|number[]} data       Series max, or the series itself.
         * @param {number}          ticks      Gridline intervals (ApexCharts `tickAmount`).
         * @param {boolean}         wholeSteps Force integer steps, for counts that cannot be fractional.
         */
        function getSmartRoundedMaxY(data, ticks = 3, wholeSteps = false) {
            const values = Array.isArray(data) ? data.filter(v => typeof v === 'number' && isFinite(v)) : [data];
            const max = values.length ? Math.max(...values) : 0;

            if (! isFinite(max) || max <= 0) {
                return { max: ticks, decimals: 0 };
            }

            const rawStep = max / ticks;
            const magnitude = Math.pow(10, Math.floor(Math.log10(rawStep)));
            const step = [1, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10]
                .map(multiplier => multiplier * magnitude)
                .find(candidate => candidate >= rawStep) ?? magnitude * 10;

            const finalStep = wholeSteps ? Math.max(1, Math.ceil(step)) : step;

            // Read the decimals off the step itself — deriving them from its magnitude gets the
            // half-steps wrong (0.15 needs two places, not the one -log10 suggests).
            const trimmed = finalStep.toPrecision(12).replace(/0+$/, '').replace(/\.$/, '');
            const point = trimmed.indexOf('.');
            const decimals = Math.min(3, point === -1 ? 0 : trimmed.length - point - 1);

            return {
                // toFixed clears the float noise that would otherwise put 0.45000000000000007 on an axis.
                max: Number((finalStep * ticks).toFixed(decimals)),
                decimals: decimals
            };
        }

        function destroyCharts() {
            if (bagsChart)         { bagsChart.destroy();         bagsChart         = null; }
            if (ebChart)           { ebChart.destroy();           ebChart           = null; }
            if (lineChartInstance) { lineChartInstance.destroy(); lineChartInstance = null; }
        }

        /*
         * Bar width follows how many columns are drawn, not which period was asked for.
         * ApexCharts sizes a bar as a share of its slot, so the same percentage reads
         * chunky over 31 slots and absurd over 2 — and the flat 25% this replaced left
         * anything that was not a day or a calendar month drawing hairlines.
         */
        function barColumnWidth(categoryCount) {
            if (categoryCount <= 2)  { return '25%'; }
            if (categoryCount <= 5)  { return '40%'; }
            if (categoryCount <= 10) { return '55%'; }

            return '70%';
        }

        function renderCharts(production, type) {
            const rawData    = Array.isArray(production?.data) ? production.data : [];
            const machines   = production.machines ?? [];
            const categories = rawData.map(e => e.period);
            const colWidth   = barColumnWidth(categories.length);

            destroyCharts();

            const allShifts = Array.isArray(production.shifts)
                ? production.shifts.map(s => ({ id: s.id, name: s.name })).sort((a, b) => a.id - b.id)
                : [];

            renderSummaryTiles(production, allShifts);

            if (type === 'day') {
                renderDayCharts(production, rawData, categories, colWidth);
                // The day's hourly rows carry the same per-machine split the range views do,
                // so a factory running more than one machine sees its lines here too.
                renderMachineChart(machines, rawData, categories, machines.length > 1);
                updateSummaryTable([], [], [], false);
                return;
            }

            renderShiftCharts(rawData, categories, colWidth, allShifts);
            renderMachineChart(machines, rawData, categories, machines.length > 1);
            updateSummaryTable(allShifts, machines, rawData, true);
        }

        // ── Day view — flat hourly bars, no shift split ──────────────────────────────
        function renderDayCharts(production, rawData, categories, colWidth) {
            const daySummaryEl = document.getElementById('bagsDaySummary');
            const bagsData = rawData.map(e => e.day_growbags ?? 0);
            const ebData   = rawData.map(e => e.eb_price     ?? 0);

            // No raw device rows for this day — the day's own totals stand in for the chart.
            // The card's empty message stays hidden: the figures below are the data, and
            // "no data available" beside them would contradict them.
            if (rawData[0]?.granularity === 'summary') {
                document.getElementById('bagsChart').style.display = 'none';
                document.getElementById('bagsChart').innerHTML = '';
                document.getElementById('bagsChartEmpty').classList.add('hidden');
                daySummaryEl.classList.remove('hidden');
                daySummaryEl.classList.add('grid');

                setCardTitle('bagsChartTitle', 'Daily Production Summary — ' + (rawData[0]?.period ?? ''));
                document.getElementById('daySummaryBags').textContent =
                    formatToIndianNumberNormal(Math.round(production.total_growbags ?? 0));
                document.getElementById('daySummaryEbUnits').textContent =
                    formatToIndianNumberNormal(Math.round(production.total_eb_units ?? 0));
                document.getElementById('daySummaryEbPrice').textContent =
                    formatToIndianRupeesNormal(Math.round(production.total_eb_price ?? 0));

                toggleChartEmpty('ebChart', 'ebChartEmpty', false);
                setCardTitle('ebChartTitle', 'EB Cost');
                return;
            }

            daySummaryEl.classList.add('hidden');
            daySummaryEl.classList.remove('grid');

            const bagsAxis = getSmartRoundedMaxY(bagsData, 3, true);
            const hasBags  = bagsData.some(v => v !== 0);

            setCardTitle('bagsChartTitle', 'Hourly Growbags Production');
            toggleChartEmpty('bagsChart', 'bagsChartEmpty', hasBags);

            if (hasBags) {
                bagsChart = new ApexCharts(document.querySelector('#bagsChart'), {
                    chart: { type: 'bar', height: 250, stacked: false },
                    colors: [growbagsChartColors.bags.shift1],
                    series: [{ name: 'Growbags', data: bagsData }],
                    xaxis: { categories, labels: { rotate: -45, rotateAlways: true } },
                    yaxis: {
                        tickAmount: 3,
                        min: 0,
                        max: bagsAxis.max,
                        decimalsInFloat: bagsAxis.decimals,
                        labels: { formatter: val => val.toFixed(bagsAxis.decimals) }
                    },
                    plotOptions: { bar: { columnWidth: colWidth } },
                    dataLabels: {
                        enabled: true,
                        formatter: val => val > 0 ? formatToIndianNumberCompact(val) : '',
                        style: { fontSize: '10px' }
                    },
                    tooltip: { shared: true, intersect: false },
                    legend: { position: 'bottom', fontSize: '12px', offsetY: -15 }
                });
                bagsChart.render();
            }

            renderEbChart([{ name: 'EB Cost', data: ebData }], categories, colWidth, 'Hourly EB Cost');
        }

        // ── Week / month / range / year — stacked by shift ───────────────────────────
        function renderShiftCharts(rawData, categories, colWidth, allShifts) {
            const bagsSeries = allShifts.map(shift => ({
                name: shift.name,
                data: rawData.map(row => {
                    const shiftData = (row.shifts ?? []).find(s => s.shift_id === shift.id);
                    return shiftData?.shift_growbags ?? 0;
                })
            }));

            const stackedTotals = rawData.map((_, i) =>
                bagsSeries.reduce((sum, s) => sum + (s.data[i] ?? 0), 0)
            );
            // Bags are counts, so the axis must step in whole bags.
            const bagsAxis = getSmartRoundedMaxY(stackedTotals, 3, true);
            const hasBags  = bagsSeries.some(s => s.data.some(v => v !== 0));

            setCardTitle('bagsChartTitle', 'Growbags Production by Shifts');
            toggleChartEmpty('bagsChart', 'bagsChartEmpty', hasBags);
            document.getElementById('bagsDaySummary').classList.add('hidden');
            document.getElementById('bagsDaySummary').classList.remove('grid');

            if (hasBags) {
                bagsChart = new ApexCharts(document.querySelector('#bagsChart'), {
                    chart: { type: 'bar', height: 250, stacked: true },
                    colors: shiftPalette(allShifts.length, growbagsChartColors.bags),
                    series: bagsSeries,
                    xaxis: { categories, labels: { rotate: -45, rotateAlways: true } },
                    yaxis: {
                        tickAmount: 3,
                        min: 0,
                        max: bagsAxis.max,
                        decimalsInFloat: bagsAxis.decimals,
                        labels: { formatter: val => val.toFixed(bagsAxis.decimals) }
                    },
                    plotOptions: {
                        bar: {
                            columnWidth: colWidth,
                            dataLabels: {
                                total: {
                                    enabled: true,
                                    style: { fontSize: '11px', fontWeight: 600 },
                                    formatter: val => formatToIndianNumberCompact(val)
                                }
                            }
                        }
                    },
                    dataLabels: { enabled: false },
                    tooltip: { shared: true, intersect: false },
                    legend: { position: 'bottom', fontSize: '12px', offsetY: -15 }
                });
                bagsChart.render();
            }

            const ebSeries = allShifts.map(shift => ({
                name: shift.name,
                data: rawData.map(row => {
                    const shiftData = (row.shifts ?? []).find(s => s.shift_id === shift.id);
                    return shiftData?.shift_eb_price ?? 0;
                })
            }));

            renderEbChart(ebSeries, categories, colWidth, 'EB Cost', allShifts.length);
        }

        function renderEbChart(ebSeries, categories, colWidth, title, shiftCount = 1) {
            const hasEb = ebSeries.some(s => s.data.some(v => v != null && v !== 0));

            setCardTitle('ebChartTitle', title);
            toggleChartEmpty('ebChart', 'ebChartEmpty', hasEb);

            if (! hasEb) {
                return;
            }

            // Rupees are never shown to the paisa here, so every label and tooltip rounds first.
            const formatEbPrice = value => '₹ ' + formatToIndianNumberNormal(Math.round(value ?? 0));

            const ebTotals = (categories ?? []).map((_, index) =>
                ebSeries.reduce((sum, series) => sum + (series.data[index] || 0), 0)
            );
            const ebAxis = getSmartRoundedMaxY(ebTotals, 3, true);

            ebChart = new ApexCharts(document.querySelector('#ebChart'), {
                chart: { type: 'bar', height: 250, stacked: true },
                colors: shiftPalette(shiftCount, growbagsChartColors.eb),
                series: ebSeries,
                xaxis: { categories, labels: { rotate: -45, rotateAlways: true } },
                yaxis: {
                    tickAmount: 3,
                    min: 0,
                    max: ebAxis.max,
                    decimalsInFloat: 0,
                    labels: { formatter: formatEbPrice }
                },
                plotOptions: {
                    bar: {
                        columnWidth: colWidth,
                        // Prices are read off the axis and the tooltip, not the bars.
                        dataLabels: { total: { enabled: false } }
                    }
                },
                dataLabels: { enabled: false },
                tooltip: { shared: true, intersect: false, y: { formatter: formatEbPrice } },
                legend: { position: 'bottom', fontSize: '12px', offsetY: -15 }
            });
            ebChart.render();
        }

        /**
         * Shift colours for a series set. The config names only two shifts, so a third falls
         * back to the machine palette rather than repeating shift 1's colour.
         */
        function shiftPalette(count, colors) {
            const base = [colors.shift1, colors.shift2];

            return Array.from({ length: Math.max(count, 1) }, (_, i) =>
                base[i] ?? machineLineColors[i % machineLineColors.length]
            );
        }

        // ── Per-machine line chart ───────────────────────────────────────────────────
        function renderMachineChart(machines, rawData, categories, enabled) {
            if (! document.getElementById('lineChart')) {
                return;
            }

            if (! enabled) {
                toggleChartEmpty('lineChart', 'machineChartEmpty', false);
                return;
            }

            const lineSeries = machines.map((m, i) => ({
                name: m.machine_name,
                data: rawData.map(row =>
                    (row.shifts ?? []).reduce((sum, shift) => {
                        const prod = (shift.production ?? []).find(p => p.machine_id === m.id);
                        return sum + (prod?.growbags ?? 0);
                    }, 0)
                ),
                color: machineLineColors[i % machineLineColors.length]
            }));

            const hasData = lineSeries.some(s => s.data.some(v => v !== 0));
            toggleChartEmpty('lineChart', 'machineChartEmpty', hasData);

            if (! hasData) {
                return;
            }

            const lineAxis = getSmartRoundedMaxY(lineSeries.flatMap(s => s.data), 3, true);

            lineChartInstance = new ApexCharts(document.querySelector('#lineChart'), {
                chart: { type: 'line', height: 250, zoom: { enabled: false } },
                series: lineSeries,
                stroke: { width: 2, dashArray: 0 },
                markers: { size: 3 },
                xaxis: { categories, labels: { rotate: -45, rotateAlways: true } },
                yaxis: {
                    tickAmount: 3,
                    min: 0,
                    max: lineAxis.max,
                    decimalsInFloat: lineAxis.decimals,
                    labels: { formatter: val => val.toFixed(lineAxis.decimals) }
                },
                tooltip: { shared: true, intersect: false },
                legend: { show: false }
            });
            lineChartInstance.render();

            // A machine unchecked before the reload has to stay unchecked after it.
            document.querySelectorAll('.machine-toggle').forEach(toggleMachineLine);
        }

        // ── Summary tiles ────────────────────────────────────────────────────────────
        function renderSummaryTiles(production, allShifts) {
            /*
             * One definition per tile, used for both the headline total and the per-shift chips
             * beneath it, so the chips can never drift from the figure they break down.
             */
            const tileMetrics = {
                bags: {
                    of: m => m.growbags ?? 0,
                    format: v => formatToIndianNumberWithHover(v)
                },
                eb: {
                    of: m => m.eb_price ?? 0,
                    format: v => formatToIndianRupeesWithHover(Math.round(v))
                }
            };

            // The period totals, shaped like a shiftSummary entry so both share one code path.
            const periodTotals = {
                growbags: production.total_growbags ?? 0,
                eb_price: production.total_eb_price ?? 0
            };

            Object.entries(tileMetrics).forEach(([metric, { of, format, chip: chipFormat }]) => {
                const tile = summaryTiles[metric];
                if (tile) {
                    setKpi(tile, format(of(periodTotals)));
                }

                const formatChip = chipFormat ?? format;

                Object.entries(production.shiftSummary ?? {}).forEach(([shiftId, metrics]) => {
                    const chip = document.querySelector(`[data-shift-metric="${metric}"][data-shift="${shiftId}"]`);
                    if (chip) {
                        chip.innerHTML = formatChip(of(metrics)) ?? '&mdash;';
                    }
                });
            });
        }

        // ── Machine × shift table ────────────────────────────────────────────────────
        function updateSummaryTable(allShifts, machines, rawData, enabled) {
            const container = document.getElementById('summaryTableContainer');
            const emptyEl   = document.getElementById('summaryTableEmpty');
            if (! container) {
                return;
            }

            if (! enabled || machines.length === 0 || allShifts.length === 0) {
                container.innerHTML = '';
                emptyEl?.classList.remove('hidden');
                return;
            }

            emptyEl?.classList.add('hidden');

            const shiftDisplayNames = {};
            allShifts.forEach((shift, index) => {
                shiftDisplayNames[shift.id] = shift.name || `Shift ${index + 1}`;
            });

            // Aggregate per machine per shift
            const machineShiftTotals = {};
            machines.forEach(m => {
                machineShiftTotals[m.id] = {
                    name:      m.machine_name,
                    total:     0,
                    eb_price:  0,
                    shifts:    {},   // growbags per shift
                    eb_shifts: {}    // eb_price per shift
                };
                allShifts.forEach(s => {
                    machineShiftTotals[m.id].shifts[s.id]    = 0;
                    machineShiftTotals[m.id].eb_shifts[s.id] = 0;
                });
            });

            rawData.forEach(row => {
                (row.shifts ?? []).forEach(shift => {
                    (shift.production ?? []).forEach(p => {
                        if (machineShiftTotals[p.machine_id]) {
                            machineShiftTotals[p.machine_id].shifts[shift.shift_id] =
                                (machineShiftTotals[p.machine_id].shifts[shift.shift_id] ?? 0) + (p.growbags ?? 0);
                            machineShiftTotals[p.machine_id].total += p.growbags ?? 0;

                            machineShiftTotals[p.machine_id].eb_shifts[shift.shift_id] =
                                (machineShiftTotals[p.machine_id].eb_shifts[shift.shift_id] ?? 0) + (p.eb_price ?? 0);
                            machineShiftTotals[p.machine_id].eb_price += p.eb_price ?? 0;
                        }
                    });
                });
            });

            // Per-shift columns only earn their width when there is more than one shift.
            const showShiftColumns = allShifts.length > 1;

            let html = `<table class="w-full text-sm border border-gray-200 rounded">
                <thead class="bg-gray-50">
                    <tr class="border-b border-gray-300">
                        <th class="caps-label text-left px-3 py-2">Machine</th>`;

            if (showShiftColumns) {
                allShifts.forEach(shift => {
                    html += `<th class="caps-label text-right px-3 py-2">${shiftDisplayNames[shift.id]} Bags</th>`;
                });
                allShifts.forEach(shift => {
                    html += `<th class="caps-label text-right px-3 py-2">${shiftDisplayNames[shift.id]} EB (₹)</th>`;
                });
            }

            html += `<th class="caps-label text-right px-3 py-2">Total Bags</th>
                    <th class="caps-label text-right px-3 py-2">Total EB (₹)</th>
                    </tr></thead><tbody>`;

            const grandShiftBags = {};
            const grandShiftEb   = {};
            allShifts.forEach(s => {
                grandShiftBags[s.id] = 0;
                grandShiftEb[s.id]   = 0;
            });
            let grandTotalBags = 0;
            let grandTotalEb   = 0;

            machines.forEach(m => {
                const mData = machineShiftTotals[m.id];
                html += `<tr class="border-b border-gray-100 hover:bg-gray-50">
                    <td class="px-3 py-2 text-gray-900">${mData.name}</td>`;

                allShifts.forEach(s => {
                    const val = mData.shifts[s.id] ?? 0;
                    grandShiftBags[s.id] += val;
                    if (showShiftColumns) {
                        html += `<td class="text-right tabular-nums px-3 py-2">${formatToIndianNumberNormal(Math.round(val))}</td>`;
                    }
                });

                allShifts.forEach(s => {
                    const val = mData.eb_shifts[s.id] ?? 0;
                    grandShiftEb[s.id] += val;
                    if (showShiftColumns) {
                        html += `<td class="text-right tabular-nums px-3 py-2">${formatToIndianRupeesNormal(Math.round(val))}</td>`;
                    }
                });

                grandTotalBags += mData.total;
                grandTotalEb   += mData.eb_price;

                html += `<td class="text-right tabular-nums px-3 py-2 font-semibold">${formatToIndianNumberNormal(Math.round(mData.total))}</td>
                        <td class="text-right tabular-nums px-3 py-2 font-semibold">${formatToIndianRupeesNormal(Math.round(mData.eb_price))}</td>
                        </tr>`;
            });

            // The total row only says something the machine row above has not when there are
            // several machines to add up.
            if (machines.length > 1) {
                html += `<tr class="border-t-2 border-gray-300 font-semibold bg-gray-50">
                    <td class="px-3 py-2" style="color: ${growbagsChartColors.bags.shift1};">Total</td>`;

                if (showShiftColumns) {
                    allShifts.forEach(s => {
                        html += `<td class="text-right tabular-nums px-3 py-2" style="color: ${growbagsChartColors.bags.shift1};">${formatToIndianNumberNormal(Math.round(grandShiftBags[s.id]))}</td>`;
                    });
                    allShifts.forEach(s => {
                        html += `<td class="text-right tabular-nums px-3 py-2" style="color: ${growbagsChartColors.bags.shift1};">${formatToIndianRupeesNormal(Math.round(grandShiftEb[s.id]))}</td>`;
                    });
                }

                html += `<td class="text-right tabular-nums px-3 py-2" style="color: ${growbagsChartColors.bags.shift1};">${formatToIndianNumberNormal(Math.round(grandTotalBags))}</td>
                        <td class="text-right tabular-nums px-3 py-2" style="color: ${growbagsChartColors.bags.shift1};">${formatToIndianRupeesNormal(Math.round(grandTotalEb))}</td>
                        </tr>`;
            }

            html += '</tbody></table>';

            container.innerHTML = html;
        }

        function toggleMachineLine(checkbox) {
            if (! lineChartInstance) {
                return;
            }

            const name = checkbox.dataset.name;
            if (checkbox.checked) {
                lineChartInstance.showSeries(name);
            } else {
                lineChartInstance.hideSeries(name);
            }
        }

        document.addEventListener('DOMContentLoaded', function () {
            const runSchedulerBtn = document.getElementById('runSchedulerBtn');

            if (runSchedulerBtn) {
                runSchedulerBtn.addEventListener('click', function () {
                    if (confirm("Do you really want to re-run yesterday's cron manually? Note that this will send all notifications and emails again.")) {
                        fetch(`/{{ $teamId }}/manual-cron-run/{{ $teamId }}`, {
                            method: 'POST',
                            headers: {
                                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                                'Content-Type': 'application/json'
                            },
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                alert(data.message);
                                window.location.reload();
                            } else {
                                alert(data.error || 'Something went wrong.');
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            alert('Failed to trigger cron. Please check the console for details.');
                        });
                    }
                });
            }
        });
    </script>

</x-app-layout>
