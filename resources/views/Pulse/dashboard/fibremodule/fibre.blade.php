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

                    <div class="flex items-center gap-2">
                        {{-- The export is a single day's bale-by-bale listing, so it only appears while one day is selected. --}}
                        <button
                            type="button"
                            id="fibreExportBtn"
                            onclick="triggerFibreExport()"
                            class="flex items-center gap-2 h-9 bg-green-600 hover:bg-green-500 text-white text-sm font-semibold px-3 rounded">
                            <i class="fa-solid fa-file-arrow-down fa-sm" aria-hidden="true"></i>
                            Export To Excel
                        </button>

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
            </div>

            @if(now()->lte($shiftStartWithBuffer))
                <div id="yesterdaySummaryMsg" class="mt-1 w-full text-center bg-blue-100 text-blue-800 px-2 py-1 rounded shadow-xs hidden">
                    Please wait until {{ \Carbon\Carbon::parse($shiftStartWithBuffer)->format('g:i a') }} to get yesterday's summary.
                </div>
            @endif
        </div>
    </x-slot>

    @php
        /*
         * The tiles carry the period total as the headline and each shift's share as a chip beneath
         * it, which is what the separate Shift Summary widget used to do at the bottom of the page.
         *
         * The two bale icons are picked so each tile matches its series in the charts below —
         * fibre-custom-icon is the red cube (produced, COLOR_PRIMARY) and fibre-weighed-icon the
         * pink one, tinted from bales.weighed_shift1, the colour weighed bars take everywhere.
         *
         * fibre-weighed-icon is its own asset rather than a recolour of fibre-icon, which was
         * shared with the growbags dashboard at the time. Growbags has since moved to the red
         * cube as well, so public/images/fibre-icon.png is now unused by anything.
         */
        // Labels are terse because the tile is one fifth of the row: the icon and the unit on the
        // figure carry the rest of the meaning, and a longer label just truncates.
        $summaryTiles = [
            ['metric' => 'bales', 'id' => 'kpiBalesProduced', 'label' => 'Produced', 'icon' => 'fibre-custom-icon.png'],
            ['metric' => 'weighed', 'id' => 'kpiBalesWeighed', 'label' => 'Weighed', 'icon' => 'fibre-weighed-icon.png'],
            ['metric' => 'weight', 'id' => 'kpiTotalWeight', 'label' => 'Weight', 'icon' => 'weight-icon.png'],
            ['metric' => 'avgWeight', 'id' => 'kpiAvgWeight', 'label' => 'Avg Weight', 'icon' => 'avg-weight-icon.png'],
            ['metric' => 'eb', 'id' => 'kpiEbCost', 'label' => 'EB Cost', 'icon' => 'line-icon.png'],
        ];
    @endphp

    <div class="w-full py-3 ps-2 sm:ps-3 lg:ps-4 pe-3">

        <div id="dashboard-error"
            class="hidden mb-3 p-3 rounded-xl border border-red-200 bg-red-50 text-sm text-red-800 flex items-center gap-2">
            <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
            <span id="dashboard-error-message"></span>
        </div>

        <!-- Summary tiles -->
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3 mb-4">
            @foreach ($summaryTiles as $tile)
                <x-dashboard.kpi :label="$tile['label']" :value-id="$tile['id']" :icon="$tile['icon']">
                    @if ($shifts->count() > 1)
                        <div class="shrink-0 flex flex-col items-end gap-1">
                            @foreach ($shifts as $shiftId => $shiftName)
                                @php
                                    $shiftIndex = $loop->index % 3 + 1;
                                    $shiftColour = config('colors.shift.'.$shiftIndex);

                                    /*
                                     * Weighed, Weight and Avg Weight all describe weighing, so their
                                     * chips are tinted from the weighed colours — the partner its bars
                                     * take in the charts below — and the tiles read as the same thing
                                     * as those bars. Produced and EB keep the shift's own colour.
                                     *
                                     * The tint carries it rather than the text: at 8% these colours
                                     * are pale enough to be legible, but as *text* they land at
                                     * 1.7-2.3:1 on their own tint, so the label stays the shift
                                     * colour and the chip goes a step stronger to compensate.
                                     */
                                    $isWeighing = in_array($tile['metric'], ['weighed', 'weight', 'avgWeight'], true);
                                    $chipTint = $isWeighing
                                        ? config('colors.fibre_charts.bales.weighed_shift'.$shiftIndex)
                                        : $shiftColour;
                                    $chipAlpha = $isWeighing ? '40' : '14';
                                @endphp
                                <span class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-xs leading-tight whitespace-nowrap"
                                    style="color: {{ $shiftColour }}; background-color: {{ $chipTint }}{{ $chipAlpha }};">
                                    <span class="font-medium">{{ $shiftName }}:</span>
                                    <span class="font-semibold" data-shift-metric="{{ $tile['metric'] }}" data-shift="{{ $shiftId }}">&mdash;</span>
                                </span>
                            @endforeach
                        </div>
                    @endif
                </x-dashboard.kpi>
            @endforeach
        </div>

        <!-- Bales — the headline metric, given the full width -->
        <div class="mb-4">
            <x-dashboard.card title="Bales Produced" title-id="balesChartTitle" empty-id="balesChartEmpty">
                <div id="balesChart" class="w-full"></div>
            </x-dashboard.card>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
            <x-dashboard.card title="Bales Weighed" title-id="weightChartTitle" empty-id="weightChartEmpty">
                <div id="weightChart" class="w-full"></div>
            </x-dashboard.card>

            <x-dashboard.card title="EB Cost" title-id="ebChartTitle" empty-id="ebChartEmpty">
                <div id="ebChart" class="w-full"></div>
            </x-dashboard.card>
        </div>

    </div>

    <!-- Loading Overlay -->
    <div id="loading-overlay" class="hidden fixed inset-0 bg-white/60 flex items-center justify-center z-50">
        <img src="{{ asset('images/loading-icon.png') }}" alt="Loading" class="w-12 h-12 animate-spin">
    </div>

    <script src="@assetVersion('js/daterangeselector.js')"></script>
    <script>
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

        // -- AJAX Fetch Function --
        function fetchDashboardData(start, end, type = 'month') {
            $.ajax({
                url: '/{{ $teamId }}/dashboard/fibre',
                method: 'GET',
                data: {
                    start: start.format('YYYY-MM-DD'),
                    end: end.format('YYYY-MM-DD'),
                    type: type
                },
                beforeSend: function () {
                    clearDashboardError();
                    showLoader();
                },
                success: function (response) {
                    // Update fibre charts with new data
                    renderBalesAndEbChart(response.data.fibreProduction, type);
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
    </script>

    <script type="text/javascript">
        const chartColors = @json(config('colors.fibre_charts'));

        // metric key → the id of the tile showing its period total. Built from the same array that
        // renders the tiles, so adding one there cannot leave the script behind.
        const summaryTiles = @json(collect($summaryTiles)->pluck('id', 'metric'));

        let balesChart = null;
        let weightChart = null;
        let ebChart = null;

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

        function renderBalesAndEbChart(fibreProduction, reportType = 'month')
        {
            if (balesChart) {
                balesChart.destroy();
                balesChart = null;
            }

            if (weightChart) {
                weightChart.destroy();
                weightChart = null;
            }

            if (ebChart) {
                ebChart.destroy();
                ebChart = null;
            }

            const rawGroupedData = Array.isArray(fibreProduction?.data) ? fibreProduction.data : [];

            const shiftDefinitions = Array.isArray(fibreProduction.shifts) ? Object.assign({}, ...fibreProduction.shifts) : {};

            // the period label
            const categories = rawGroupedData.map(entry => entry.period);

            /*
             * Weighed is one metric, so it is one colour wherever it is drawn: the weighed bars in
             * "Bales Produced & Weighed", the whole of "Hourly Bales Weighed" and "Bales Weighed",
             * and the per-shift chips on the Weighed tile above. Those last three used to take the
             * full-strength shift colours, which is what "produced" wears — so a weight chart read
             * as a production chart, and the two sat side by side saying different things.
             *
             * Indexed by shift, so a third shift gets its own partner rather than borrowing one.
             */
            const weighedColours = [
                chartColors.bales.weighed_shift1,
                chartColors.bales.weighed_shift2,
                chartColors.bales.weighed_shift3
            ];

            // ─── Bales Produced (and Weighed) Chart ──────────────────────────────────────────────────────────
            let balesSeries;
            let chartTitle;
            let hasWeighedData = false;
            let chartColorsArray = []; // Renamed to avoid conflict with the constant
            if (reportType === 'day') {
                // ---- "Date" mode: flat hourly data, no shifts ----
                const producedSeries = {
                    name: 'Produced',
                    data: rawGroupedData.map(e => e.bales_produced ?? 0)
                };
                const weighedSeries = {
                    name: 'Weighed',
                    data: rawGroupedData.map(e => e.bales_weighed ?? 0)
                };

                hasWeighedData = weighedSeries.data.some(v => v !== 0);

                balesSeries = [
                    producedSeries,
                    ...(hasWeighedData ? [weighedSeries] : [])
                ];

                chartColorsArray = hasWeighedData
                ? [chartColors.bales.hourly_produced, chartColors.bales.hourly_weighed]
                : [chartColors.bales.hourly_produced]; // only produced
                chartTitle = hasWeighedData
                    ? 'Bales Produced & Weighed (in counts)'
                    : 'Bales Produced';
            } else {
                // ---- Shift-based modes (week/month/year) ----
                const producedArr = buildShiftSeries(shiftDefinitions, rawGroupedData, 'Produced', 'bales_produced', 'Produced');
                const weighedArr = buildShiftSeries(shiftDefinitions, rawGroupedData, 'Weighed', 'bales_weighed', 'Weighed');

                hasWeighedData = weighedArr.some(s => s.data.some(v => v !== 0));

                /*
                 * Hue is the shift, lightness is the metric: shift N's produced bar is its own
                 * colour, its weighed bar the lighter partner of that colour. Indexing by shift
                 * rather than testing for index 0 also stops a third shift borrowing shift 2's.
                 */
                const producedColours = [
                    chartColors.bales.produced_shift1,
                    chartColors.bales.produced_shift2,
                    chartColors.bales.produced_shift3
                ];
                balesSeries = [...producedArr];
                chartColorsArray = producedArr.map((_, index) => producedColours[index % producedColours.length]);

                if (hasWeighedData) {
                    weighedArr.forEach((series, index) => {
                        balesSeries.push(series);
                        chartColorsArray.push(weighedColours[index % weighedColours.length]);
                    });
                }

                chartTitle = hasWeighedData
                    ? 'Bales Produced & Weighed (in counts)'
                    : 'Bales Produced (in counts)';
            }

            const chartColWidth = barColumnWidth(categories.length);

            // Calculate the real stacked max (per group, not all series)
            const producedTotals = rawGroupedData.map((e, i) => {
                return balesSeries
                    .filter(s => s.name.includes('Produced'))  // only produced series
                    .reduce((sum, s) => sum + (s.data[i] || 0), 0);
            });

            const weighedTotals = rawGroupedData.map((e, i) => {
                return balesSeries
                    .filter(s => s.name.includes('Weighed'))
                    .reduce((sum, s) => sum + (s.data[i] || 0), 0);
            });

            const realMax = Math.max(...producedTotals, ...weighedTotals);
            // Bales are counts, so the axis must step in whole bales.
            const balesAxis = getSmartRoundedMaxY(realMax, 3, true);

            var options = {
                chart: {
                    type: 'bar',
                    height: 250,
                    stacked: reportType !== 'day',
                    toolbar: {
                        export: {
                            csv: {
                                headerCategory: 'Date',
                            }
                        }
                    },
                },
                colors: chartColorsArray,
                plotOptions: {
                    bar: {
                        columnWidth: chartColWidth,
                        dataLabels: {
                            total: {
                                enabled: (!hasWeighedData || reportType === 'day'),
                                style: {
                                    fontSize: '11px',
                                    fontWeight: 600
                                },
                                formatter: function (val) {
                                    return formatToIndianNumberCompact(val);
                                }
                            }
                        }
                    }
                },
                dataLabels: {
                    enabled: (!hasWeighedData || reportType === 'day'),
                    style: {
                        fontSize: '10px'
                    },
                    formatter: function (val) {
                        return formatToIndianNumberCompact(val);
                    }
                },
                xaxis: {
                    categories: categories,
                    labels: { rotate: -45, rotateAlways: true }
                },
                yaxis: {
                    tickAmount: 3,
                    min: 0,
                    max: balesAxis.max,
                    decimalsInFloat: balesAxis.decimals,
                    labels: {
                        formatter: function(val) {
                            return val.toFixed(balesAxis.decimals);
                        }
                    }
                },
                series: balesSeries,
                tooltip: {
                    shared: true,
                    intersect: false
                },
                legend: {
                    position: 'bottom',
                    fontSize: "12px",
                    offsetY: -15
                }
            }

            // The card's <h3> is the heading now — rendering an ApexCharts title too would double it.
            setCardTitle('balesChartTitle', chartTitle);

            const hasBalesData = balesSeries.some(s => s.data.some(v => v != null && v !== 0));
            toggleChartEmpty('balesChart', 'balesChartEmpty', hasBalesData);

            if (hasBalesData) {
                balesChart = new ApexCharts(document.querySelector("#balesChart"), options);
                balesChart.render();
            }

            // ─── Weight Chart ──────────────────────────────────────────────────────────
            let weightSeries;
            let weightTitle;
            // Tonnages are fractional — an hourly peak is often under 2 t — so the axis keeps
            // decimal steps rather than collapsing every gridline onto the same integer.
            let weightAxis;

            if (reportType === 'day') {
                // Date mode → flat “total_weight” series
                const totalWeightData = rawGroupedData.map(e => e.total_weight ?? 0);
                weightSeries = [{
                    name: 'Total Weight',
                    type: 'bar',  // Use bar type for total weight
                    data: totalWeightData
                }];
                weightTitle = 'Hourly Bales Weighed';
                weightAxis = getSmartRoundedMaxY(totalWeightData);
            } else {
                // Shift-based modes → existing shift logic
                weightSeries = buildShiftSeries(
                    shiftDefinitions,
                    rawGroupedData,
                    'Weight',
                    'weight',
                    'Weight'
                );
                // add type: 'bar' to each series
                weightSeries.forEach(s => {
                    s.type = 'bar';
                    s.yAxisIndex = 0;  // ← all weight bars use left axis
                });
                weightTitle = 'Bales Weighed';

                // Step 1: Get number of data points (assuming all series have same length)
                const dataLength = weightSeries[0].data.length;

                // Step 2: Sum values across shifts for each index
                const combinedValues = [];

                for (let i = 0; i < dataLength; i++) {
                    let sum = 0;

                    for (const series of weightSeries) {
                        sum += series.data[i] || 0;
                    }

                    combinedValues.push(sum);
                }

                // Step 3: Get max of combined values
                const maxWeightValue = Math.max(...combinedValues);

                // Step 4: Apply your rounding logic
                weightAxis = getSmartRoundedMaxY(maxWeightValue);
            }

            const averageWeightData = rawGroupedData.map(e => (e.average_weight && e.average_weight !== 0) ? e.average_weight : null);
            const minYAxisValue = getRoundedMinY(averageWeightData);
            let averageWeightSeries = [{
                name: 'Avg. Weight',
                type: 'line',
                yAxisIndex: 1,     // ← line uses right axis
                data: averageWeightData
            }];

            const hasWeightData = weightSeries.some(s => s.data.some(v => v != null && v !== 0));

            if (hasWeightData) {
                options = {
                    chart: {
                        type: 'line',
                        height: 250,
                        stacked: reportType !== 'day',
                        zoom: {
                            enabled: false
                        }
                    },
                    colors: reportType === 'day'
                        ? [chartColors.bales.hourly_weighed, chartColors.weights.average]
                        : [
                            ...weightSeries.map((_, index) => weighedColours[index % weighedColours.length]),
                            chartColors.weights.average
                        ],
                    plotOptions: {
                        bar: {
                            columnWidth: chartColWidth,
                            // Weight figures are read off the axis and the tooltip, not the bars.
                            dataLabels: {
                                total: { enabled: false }
                            }
                        }
                    },
                    stroke: {
                        width: [...weightSeries.map(() => 1), 2] // last one = Avg. Weight line
                    },
                    markers: {
                        size: 3, // Size of the dots on the line
                        colors: [chartColors.weights.average],    // Color of the dots
                        strokeColors: '#FFFFFF', // Border color of the dots
                        strokeWidth: 1, // Border width of the dots
                        hover: {
                            size: 3, // Increase dot size on hover
                        }
                    },
                    dataLabels: {
                        enabled: false
                    },
                    xaxis: {
                        categories: categories,
                        labels: { rotate: -45, rotateAlways: true }
                    },
                    yaxis: [
                        {
                            seriesName: weightSeries[0].name,  // ← anchor to first bar series
                            title: { text: 'Total Weight (tons)' },
                            tickAmount: 3,
                            min: 0,
                            max: weightAxis.max,
                            decimalsInFloat: weightAxis.decimals
                        },
                        // If 2 shift series, you need a hidden duplicate for shift2
                        // so it doesn't hijack the opposite axis
                        ...(weightSeries.length > 1 ? [{
                            seriesName: weightSeries[0].name,  // ← same name = hidden duplicate
                            show: false                         // ← hides it, but keeps axis binding
                        }] : []),
                        {
                            seriesName: 'Avg. Weight',         // ← anchor line to this axis
                            opposite: true,
                            title: { text: 'Avg. Weight (kgs)' },
                            min: minYAxisValue,
                            decimalsInFloat: 0
                        }
                    ],
                    series: [...weightSeries, ...averageWeightSeries],
                    tooltip: {
                        shared: true,
                        intersect: false,
                        y: {
                            formatter: function (val, opts) {
                                val = val ?? 0;

                                const barSeriesCount = weightSeries.length;
                                const isBarSeries = opts.seriesIndex < barSeriesCount;

                                return isBarSeries
                                    ? formatToIndianNumberWithHover(val, 2) + " tons"
                                    : formatToIndianNumberWithHover(val, 1) + " kgs";
                            }
                        }
                    },
                    legend: {
                        position: 'bottom',
                        fontSize: "12px",
                        offsetY: -15,
                        /*
                         * This chart is chart.type 'line' — it carries the Avg. Weight line
                         * alongside the bars — and ApexCharts takes the legend marker shape from
                         * that, so its swatches came out round while every other card's were
                         * square. The shape is describing the chart's plumbing rather than
                         * anything about the data, so pin it to match the rest.
                         */
                        markers: {
                            shape: 'square'
                        }
                    }
                }
            }

            setCardTitle('weightChartTitle', weightTitle);
            toggleChartEmpty('weightChart', 'weightChartEmpty', hasWeightData);

            if (hasWeightData) {
                weightChart = new ApexCharts(document.querySelector("#weightChart"), options);
                weightChart.render();
            }

            // ─── EB Units Chart ──────────────────────────────────────────────────────────
            let ebPriceSeries = [];
            let ebTitle = 'EB Cost';

            if (reportType === 'day') {
                ebPriceSeries = [{
                    name: 'EB Cost',
                    data: rawGroupedData.map(e => e.eb_price ?? 0)
                }];
                ebTitle = 'Hourly EB Cost';
            } else {
                ebPriceSeries = buildShiftSeries(shiftDefinitions, rawGroupedData, 'EB Cost', 'eb_price');
            }

            const hasEbData = ebPriceSeries.some(s => s.data.some(v => v != null && v !== 0));

            // Rupees are never shown to the paisa here, so every label and tooltip rounds first.
            const formatEbPrice = value => '₹ ' + formatToIndianNumberNormal(Math.round(value ?? 0));

            const ebTotals = (categories ?? []).map((_, index) =>
                ebPriceSeries.reduce((sum, series) => sum + (series.data[index] || 0), 0)
            );
            const ebAxis = getSmartRoundedMaxY(ebTotals, 3, true);

            options = {
                chart: {
                    type: 'bar',
                    height: 250,
                    stacked: true,
                },
                colors: ebPriceSeries.map((_, index) =>
                    index === 0
                        ? chartColors.eb.shift1
                        : chartColors.eb.shift2
                ),
                plotOptions: {
                    bar: {
                        columnWidth: chartColWidth,
                        // Prices are read off the axis and the tooltip, not the bars.
                        dataLabels: {
                            total: { enabled: false }
                        }
                    }
                },
                dataLabels: {
                    enabled: false
                },
                xaxis: {
                    categories: categories,
                    labels: { rotate: -45, rotateAlways: true }
                },
                yaxis: {
                    tickAmount: 3,
                    min: 0,
                    max: ebAxis.max,
                    decimalsInFloat: 0,
                    labels: {
                        formatter: formatEbPrice
                    }
                },
                series: ebPriceSeries,
                tooltip: {
                    shared: true,
                    intersect: false,
                    y: {
                        formatter: formatEbPrice
                    }
                },
                legend: {
                    position: 'bottom',
                    fontSize: "12px",
                    offsetY: -15
                }
            }

            setCardTitle('ebChartTitle', ebTitle);
            toggleChartEmpty('ebChart', 'ebChartEmpty', hasEbData);

            if (hasEbData) {
                ebChart = new ApexCharts(document.querySelector("#ebChart"), options);
                ebChart.render();
            }


            // ─── Summary Tiles ───────────────────────────────────────────────────────────
            /*
             * One definition per tile, used for both the headline total and the per-shift chips
             * beneath it, so the chips can never drift from the figure they break down.
             *
             * The two derived metrics need a denominator; `format` returns null when there isn't
             * one and the tile falls back to its em dash rather than printing Infinity or NaN.
             */
            const tileMetrics = {
                bales: {
                    of: m => m.bales_produced ?? 0,
                    format: v => formatToIndianNumberWithHover(v)
                },
                weighed: {
                    of: m => m.bales_weighed ?? 0,
                    format: v => formatToIndianNumberWithHover(v)
                },
                // `chip` drops the unit: the tile's own figure right beside it already states it,
                // and at a fifth of the row the repetition is what pushes the label into truncating.
                weight: {
                    of: m => m.weight ?? 0,
                    format: v => withUnit(formatToIndianNumberWithHover(round1(v)), 'tons'),
                    chip: v => formatToIndianNumberWithHover(round1(v))
                },
                avgWeight: {
                    of: m => (m.bales_weighed ?? 0) > 0 ? ((m.weight ?? 0) * 1000) / m.bales_weighed : null,
                    format: v => v === null ? null : withUnit(formatToIndianNumberWithHover(round1(v)), 'kg'),
                    chip: v => v === null ? null : formatToIndianNumberWithHover(round1(v))
                },
                eb: {
                    of: m => m.eb_price ?? 0,
                    format: v => formatToIndianRupeesWithHover(Math.round(v))
                }
            };

            // The period totals, shaped like a shiftSummary entry so both share one code path.
            const periodTotals = {
                bales_produced: fibreProduction.total_bales_produced ?? 0,
                bales_weighed: fibreProduction.total_bales_weighed ?? 0,
                weight: fibreProduction.total_weight ?? 0,
                eb_price: fibreProduction.total_eb_price ?? 0
            };

            Object.entries(tileMetrics).forEach(([metric, { of, format, chip: chipFormat }]) => {
                const tile = summaryTiles[metric];
                if (tile) {
                    setKpi(tile, format(of(periodTotals)));
                }

                const formatChip = chipFormat ?? format;

                Object.entries(fibreProduction.shiftSummary ?? {}).forEach(([shiftId, metrics]) => {
                    const chip = document.querySelector(`[data-shift-metric="${metric}"][data-shift="${shiftId}"]`);
                    if (chip) {
                        chip.innerHTML = formatChip(of(metrics)) ?? '&mdash;';
                    }
                });
            });
        }

        function round1(value) {
            return Math.round(value * 10) / 10;
        }

        /**
         * Set the unit in smaller, lighter type beside the figure. It keeps the number itself the
         * thing you read first, and at a fifth of the row a full-size unit is what tipped the
         * longer values into truncating.
         */
        function withUnit(value, unit) {
            return value + '<span class="text-xs font-normal text-gray-500 ms-0.5">' + unit + '</span>';
        }

        // Helper to extract metric for each shift over the periods
        function buildShiftSeries(shiftDefinitions, rawGroupedData, labelSuffix, metricKey, group = null) {
            return Object.entries(shiftDefinitions).map(([shiftId, shiftName]) => {
                const data = (rawGroupedData ?? []).map(entry => {
                    const s = entry?.shifts?.find(s => s.shift_id === Number(shiftId));
                    return s?.[metricKey] ?? 0;
                });

                return {
                    name: `${shiftName} ${labelSuffix}`,
                    type: 'bar',
                    ...(group && { group }),
                    data
                };
            });
        }

        function getRoundedMinY(data) {
            const validValues = data.filter(v => typeof v === 'number');
            const min = Math.min(...validValues);
            return Math.floor(min / 10) * 10;
        }

        /**
         * Pick an axis maximum that divides evenly into `ticks` steps, plus the number of decimals
         * those steps need.
         *
         * The old version rounded the max up to a power of ten and left ApexCharts to divide it by
         * the tick count, which produced fractional ticks that `decimalsInFloat: 0` then rounded to
         * duplicate labels: an hourly weight peaking at 1.3 t got max 2 and ticks 0 / 0.67 / 1.33 / 2,
         * printed as "0, 1, 1, 2". Choosing the step first and deriving the max from it keeps every
         * gridline on a round number.
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

            // Read the decimals off the step itself. Deriving them from its magnitude instead gets
            // the half-steps wrong — 0.15 needs two places, not the one -log10 suggests — which is
            // the same duplicate-label problem one rung down.
            const trimmed = finalStep.toPrecision(12).replace(/0+$/, '').replace(/\.$/, '');
            const point = trimmed.indexOf('.');
            const decimals = Math.min(3, point === -1 ? 0 : trimmed.length - point - 1);

            return {
                // toFixed clears the float noise that would otherwise put 0.45000000000000007 on an axis.
                max: Number((finalStep * ticks).toFixed(decimals)),
                decimals: decimals
            };
        }

        document.addEventListener('DOMContentLoaded', function() {
            const runSchedulerBtn = document.getElementById('runSchedulerBtn');

            if (runSchedulerBtn) {
                runSchedulerBtn.addEventListener('click', function() {
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

    <script>
    /*
     * The export lists a day's bales one by one, so it is offered only while a
     * single day is selected — the button follows the period selector.
     */
    $(document).on('pulse:range-changed', function (event, range) {
        $('#fibreExportBtn').toggle(range.isSingleDay);
    });

    function triggerFibreExport() {
        let start, end;
        try {
            start = selectedRangeStart.format('YYYY-MM-DD');
            end   = selectedRangeEnd.format('YYYY-MM-DD');
        } catch (e) {
            console.error('Export error reading dates:', e);
            alert('Could not read the selected date. Please try again.');
            return;
        }

        const url = `/{{ $teamId }}/dashboard/fibre/export?start=${start}&end=${end}&type=${selectedRangeType}`;

        const a = document.createElement('a');
        a.href = url;
        a.download = '';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
    }
    </script>

</x-app-layout>