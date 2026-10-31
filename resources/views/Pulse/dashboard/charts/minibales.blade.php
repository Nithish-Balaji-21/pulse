<div class="h-full bg-white rounded shadow-[0_0_10px_rgba(0,0,0,0.15)] px-4 pt-4">
    <div class="flex justify-between items-center mb-2">
        <!-- Chart Title -->
        <h3 class="text-base font-semibold text-gray-800">Fibre Bales Production</h3>
        <div class="flex flex-1 items-center justify-end gap-4">
            <!-- Summary Metrics -->
            <div id="fibre-minibales-summary" class="flex items-center space-x-4 text-xs font-medium text-gray-700">
                @if (auth()->user()->currentTeam && auth()->user()->currentTeam->has_pith_module_bales)
                    <!-- Total Expected Bales -->
                    <span class="flex items-center">
                        <span class="w-2.5 h-2.5 rounded-full mr-1 shrink-0" style="background-color: {{ config('colors.minibales_charts.expected') }};"></span>
                        Estimated (<span id="total-bales-expected" class="font-semibold tabular-nums">0</span>)
                    </span>
                @endif
                <!-- Total Actual Bales -->
                <span id="fibre-actual-summary" class="hidden items-center">
                    <span class="w-2.5 h-2.5 rounded-full mr-1 shrink-0" style="background-color: {{ config('colors.minibales_charts.actual') }};"></span>
                    Actuals (<span id="total-bales-actuals" class="font-semibold tabular-nums">0</span>)
                </span>
            </div>

            @if (auth()->user()->currentTeam->showActuals())
            <!-- Toggle button -->
            <label class="relative inline-flex items-center cursor-pointer text-xs text-gray-600">
                <input id="fibre-show-actuals" type="checkbox" class="sr-only">
                <span class="fibre-toggle-track h-5 w-10 rounded-full bg-gray-300 transition-colors"></span>
                <span class="fibre-toggle-knob absolute left-0.5 top-0.5 h-4 w-4 rounded-full bg-white shadow transition-transform"></span>
                <span class="ml-2">Show Actuals</span>
            </label>
            <style>
                /* Toggle: when checked make track brown (use expected color from config) */
                #fibre-show-actuals:checked + .fibre-toggle-track {
                    background-color: {{ config('colors.minibales_charts.expected') }} !important;
                }
                /* Move knob when checked (match translate-x-5) */
                #fibre-show-actuals:checked + .fibre-toggle-track + .fibre-toggle-knob {
                    transform: translateX(1.25rem); /* 20px ~= translate-x-5 */
                }
            </style>
            @endif
        </div>
    </div>
    <hr class="border-t border-gray-400 my-2">

    <div id="fibre-minibales-chart"></div>
    <p id="fibre-minibales-empty" class="hidden text-sm text-gray-700 mt-2 pb-4">No data available for the selected duration.</p>
</div>

<script>
    const fibreMiniBalesChartColors = @json(config('colors.minibales_charts'));
    var fibreMiniBalesChart = null;
    var lastFibreProduction = null;

    function renderFibreMiniBalesChart(fibreProduction)
    {
        lastFibreProduction = fibreProduction;

        // Destroy previous chart if exists
        if (fibreMiniBalesChart) {
            fibreMiniBalesChart.destroy();
            fibreMiniBalesChart = null;
        }
        const chartContainer = document.getElementById('fibre-minibales-chart');
        const emptyMessage = document.getElementById('fibre-minibales-empty');
        // Summary Metrics
        const totalExpectedSpan = document.getElementById("total-bales-expected");
        const totalActualSpan = document.getElementById("total-bales-actuals");
        const actualSummary = document.getElementById("fibre-actual-summary");
        const showActuals = document.getElementById("fibre-show-actuals")?.checked ?? false;

        // Check if fibreProduction exists and has data
        if (!fibreProduction || !fibreProduction.data || !Array.isArray(fibreProduction.data) || fibreProduction.data.length === 0) {
            chartContainer.innerHTML = '';
            chartContainer.style.display = 'none';
            emptyMessage.classList.remove('hidden');
            // Reset Summary Spans on No Data
            if (totalExpectedSpan) totalExpectedSpan.textContent = '0';
            if (totalActualSpan) totalActualSpan.textContent = '0';
            return;
        }

        const hasValues = fibreProduction.data.some(item => item.bales_produced > 0 ||
                            item.bales_expected > 0);
        if (!hasValues) {
            chartContainer.innerHTML = '';
            chartContainer.style.display = 'none';
            emptyMessage.classList.remove('hidden');
            // Reset Summary Spans on No Data
            if (totalExpectedSpan) totalExpectedSpan.textContent = '0';
            if (totalActualSpan) totalActualSpan.textContent = '0';
            return;
        }

        emptyMessage.classList.add('hidden'); // Hide the message
        chartContainer.style.display = 'block';

        const categories = fibreProduction.data.map(item => item.period);

        const balesProduced = safeExtract(fibreProduction.data, 'bales_produced');
        const hasBalesProduced = balesProduced.some(val => val > 0);


        @if (auth()->user()->currentTeam && auth()->user()->currentTeam->has_pith_module_bales)
            const balesExpected = safeExtract(fibreProduction.data, 'bales_expected');
            const totalExpected = fibreProduction.total_bales_expected || 0;
        @endif

        // Update Summary Spans
        const formatFn = typeof formatToIndianNumberWithHover === 'function' ? formatToIndianNumberWithHover : String;

        if (totalExpectedSpan) {
            const expectedVal = @if (auth()->user()->currentTeam && auth()->user()->currentTeam->has_pith_module_bales) totalExpected @else 0 @endif;
            totalExpectedSpan.innerHTML = formatFn(expectedVal);
        }

        if (totalActualSpan) {
            totalActualSpan.innerHTML = formatFn(fibreProduction.total_bales_produced || 0);
        }

        const series = [];
        const tooltipY = [];
        const colors = [];

        @if (auth()->user()->currentTeam && auth()->user()->currentTeam->has_pith_module_bales)
            series.push({
                name: "Bales Estimated",
                data: balesExpected,
            });
            colors.push(fibreMiniBalesChartColors.expected);
            tooltipY.push({ formatter: val => typeof formatToIndianNumberWithHover === 'function' ? formatToIndianNumberWithHover(val) : val });
        @endif

        if (showActuals && hasBalesProduced) {
            series.push({
                name: "Bales Produced",
                data: balesProduced,
            });
            colors.push(fibreMiniBalesChartColors.actual);
            tooltipY.push({ formatter: val => typeof formatToIndianNumberWithHover === 'function' ? formatToIndianNumberWithHover(val) : val });
        }

        if (actualSummary) {
            actualSummary.classList.toggle('hidden', !showActuals);
            actualSummary.classList.toggle('flex', showActuals);
        }

        const maxValue = roundUpToNearest(Math.max(
            ...series.flatMap(item => item.data),
            0
        ));

        const options = {
            series: series,
            chart: {
                height: 250,
                type: "bar",
                stacked: false,
                toolbar: {
                    export: {
                        csv: {
                            headerCategory: 'Date',
                        },
                    },
                },
                zoom: { enabled: false },
            },
            plotOptions: {
                bar: {
                    columnWidth: '75%',
                    dataLabels: {
                        position: 'top',
                    },
                },
            },
            stroke: {
                width: series.map(() => 1),
            },
            markers: {
                size: 4,
                strokeWidth: 1,
                hover: {
                    size: 6,
                },
            },
            colors: colors,
            xaxis: {
                categories: categories,
                labels: {
                    rotate: categories.length > 3 ? -45 : 0,
                    rotateAlways: categories.length > 3,
                    style: {
                        fontSize: "11px",
                    },
                },
            },
            dataLabels: {
                enabled: false,
                style: { fontSize: '11px' },
                background: { enabled: false },
            },
            yaxis: {
                min: 0,
                max: maxValue,
                decimalsInFloat: 0,
                tickAmount: 4,
                title: { text: "Bales", style: { fontWeight: 500 } }
            },
            legend: { show: false },
            tooltip: {
                shared: true,
                intersect: false,
                fixed: {
                    enabled: false,
                    position: 'topLeft',
                    offsetY: 30,
                    offsetX: 60,
                },
                y: tooltipY,
            },
        };

        try {
            fibreMiniBalesChart = new ApexCharts(document.querySelector("#fibre-minibales-chart"), options);
            fibreMiniBalesChart.render();
        } catch (error) {
            console.error('Error rendering fibre minibales chart:', error);
            chartContainer.innerHTML = '';
            chartContainer.style.display = 'none';
            emptyMessage.classList.remove('hidden');
        }
    }

    function safeExtract(data, key) {
        return data.map(item => typeof item[key] === 'number' ? item[key] : 0);
    }

    document.addEventListener('change', function (event) {
        if (event.target?.id === 'fibre-show-actuals' && lastFibreProduction) {
            renderFibreMiniBalesChart(lastFibreProduction);
        }
    });

</script>