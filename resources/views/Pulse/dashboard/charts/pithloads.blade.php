<div id="pithLoadsWidget" class="h-full bg-white rounded shadow-[0_0_10px_rgba(0,0,0,0.15)] px-4 pt-4">
    <div class="flex flex-wrap justify-between items-start gap-2 mb-2">
        <div class="flex items-center gap-3">
            <h3 class="text-base font-semibold text-gray-800">Pith CFT Production</h3>
        </div>

        <!-- Summary Metrics — legend with aligned dots -->
        <div id="pith-loads-summary" class="flex items-center gap-4 text-xs font-medium text-gray-700">

            <!-- Total Expected Loads -->
            <span id="total-loads-expected-wrapper" class="flex items-center gap-1.5">
                <span class="w-2.5 h-2.5 rounded-full shrink-0" style="background-color: {{ config('colors.pith_load_charts.expected') }};"></span>
                <span>Estimated (<span id="total-loads-expected" class="font-semibold tabular-nums">0</span>)</span>
            </span>

            @if (auth()->user()->currentTeam->showActuals())
            <!-- Total Actual Loads -->
            <span id="total-loads-actuals-wrapper" class="flex items-center gap-1.5">
                <span class="w-2.5 h-2.5 rounded-full shrink-0" style="background-color: {{ config('colors.pith_load_charts.actual') }};"></span>
                <span>Actuals (<span id="total-loads-actuals" class="font-semibold tabular-nums">0</span>)</span>
            </span>
            @endif

            <!-- Total EB Cost -->
            <span class="flex items-center gap-1.5">
                <span class="w-2.5 h-2.5 rounded-full shrink-0" style="background-color: {{ config('colors.pith_load_charts.eb_units') }};"></span>
                <span>EB Cost (<span id="total-eb-price" class="font-semibold tabular-nums">0</span>)</span>
            </span>
        </div>
    </div>

    <hr class="border-t border-gray-400 my-2">
    <div id="pith-loads-chart"></div>

    <p id="pith-loads-empty" class="hidden text-sm text-gray-700 mt-2 pb-4">
        No data available for the selected duration.
    </p>
</div>

<script>
    const pithLoadChartColors = @json(config('colors.pith_load_charts'));
    window.showActuals = {{ auth()->user()->currentTeam->showActuals() ? 'true' : 'false' }};
    var pithLoadsChart = null;

    // Formatter: full Indian-format number (e.g., 1,18,799)
    function formatToIndianNumberNormal(num, decimals = 0) {
        if (num === null || num === undefined || num === '' || Number.isNaN(Number(num))) return '—';
        const n = Number(num);
        const fixed = decimals > 0 ? n.toFixed(decimals) : Math.round(n).toString();
        // Split integer and fraction
        const parts = (decimals > 0 ? Number(n).toFixed(decimals) : Math.round(n).toString()).split('.');
        let intPart = parts[0];
        const fracPart = parts[1] ? '.' + parts[1] : '';
        // Indian grouping
        let lastThree = intPart.slice(-3);
        const otherNumbers = intPart.slice(0, -3);
        if (otherNumbers !== '') {
            lastThree = ',' + lastThree;
            intPart = otherNumbers.replace(/\B(?=(\d{2})+(?!\d))/g, ',') + lastThree;
        }
        return (intPart + fracPart).replace(/\.00$/, '');
    }

    // Use the global standard formatting functions
    function formatToIndianNumberWithHover(num) {
        return typeof formatToIndianNumberNormal === 'function' ? formatToIndianNumberNormal(num) : num;
    }

    function renderPithLoadsChart(pithProduction, _internal)
    {
        // Destroy previous chart if exists
        if (pithLoadsChart) {
            pithLoadsChart.destroy();
            pithLoadsChart = null;
        }

        const chartContainer = document.getElementById('pith-loads-chart');
        const emptyMessage = document.getElementById('pith-loads-empty');

        // Summary Metrics
        const totalExpectedSpan = document.getElementById("total-loads-expected");
        const totalActualSpan = document.getElementById("total-loads-actuals");
        const totalEBPriceSpan = document.getElementById("total-eb-price");

        const hasValues = pithProduction.data.some(item => item.loads_expected > 0 ||
                                            item.loads_actuals > 0 ||
                                            item.eb_price > 0);
        if (!hasValues) {
            chartContainer.innerHTML = '';
            chartContainer.style.display = 'none';
            emptyMessage.classList.remove('hidden');

            // Reset Summary Spans on No Data
            if (totalExpectedSpan) totalExpectedSpan.textContent = '0';
            if (totalActualSpan) totalActualSpan.textContent = '0';
            if (totalEBPriceSpan) totalEBPriceSpan.textContent = '0';

            return;
        }

        chartContainer.style.display = 'block';
        emptyMessage.classList.add('hidden');

        const categories   = pithProduction.data.map(item => item.period);

        const ebPriceData  = pithProduction.data.map(item => item.eb_price);
        const expectedData = pithProduction.data.map(item => item.loads_expected);
        const actualsData  = showActuals ? pithProduction.data.map(item => item.loads_actuals) : pithProduction.data.map(() => null);
        const maxValue     = roundUpToNearest(Math.max(...[...expectedData, ...actualsData]));

        // Update Summary Spans — sum from the current dataset shown in the chart
        const formatFn = typeof formatToIndianNumberWithHover === 'function' ? formatToIndianNumberWithHover : String;

        if (totalExpectedSpan) {
            const sliceExpected = expectedData.reduce((s, v) => s + (v || 0), 0);
            const compact = typeof formatToIndianNumber === 'function' ? formatToIndianNumber(sliceExpected, false, false, false) : sliceExpected;
            const full = formatToIndianNumberNormal(sliceExpected, 2);
            totalExpectedSpan.innerHTML = compact;
            const wrapper = document.getElementById('total-loads-expected-wrapper');
            if (wrapper) {
                wrapper.title = 'Estimated: ' + full + ' cft';
            }
            totalExpectedSpan.title = 'Estimated: ' + full + ' cft';
        }

        if (totalActualSpan) {
            const sliceActual = actualsData.reduce((s, v) => s + (v || 0), 0);
            const compact = typeof formatToIndianNumber === 'function' ? formatToIndianNumber(sliceActual, false, false, false) : sliceActual;
            const full = formatToIndianNumberNormal(sliceActual, 2);
            totalActualSpan.innerHTML = compact;
            const wrapperA = document.getElementById('total-loads-actuals-wrapper');
            if (wrapperA) {
                wrapperA.title = 'Actuals: ' + full + ' cft';
            }
            totalActualSpan.title = 'Actuals: ' + full + ' cft';
        }
        if (totalEBPriceSpan) {
            const sliceEB = ebPriceData.reduce((s, v) => s + (v || 0), 0);
            totalEBPriceSpan.innerHTML = '₹' + (typeof formatToIndianNumberNormal === 'function' ? formatToIndianNumberNormal(Math.round(sliceEB)) : Math.round(sliceEB));
        }

        var rotateValue = 0;
        var rotateAlways = false;
        if (categories.length > 3) {
            rotateValue = -45;
            rotateAlways = true;
        }

        var options = {
            series: [
                {
                    name: "Estimated",
                    type: "column",
                    data: expectedData,
                },
                {
                    name: "Actuals",
                    type: "column",
                    data: actualsData,
                },
                {
                    name: "EB Cost",
                    type: "line",
                    data: ebPriceData,
                },
            ],
            chart: {
                height: 250,
                type: "line",
                stacked: false,
                toolbar: {
                    export: {
                        csv: {
                            headerCategory: 'Date',
                        }
                    }
                },
                zoom: {
                    enabled: false
                }
            },
            plotOptions: {
                bar: {
                    columnWidth: '75%',
                    dataLabels: {
                        position: 'top',
                    }
                }
            },
            stroke: {
                width: [1, 1, 3]
            },
            markers: {
                size: 4, // Size of the dots on the line
                colors: [pithLoadChartColors.eb_units], // Use CSS variable for EB Units
                strokeColors: pithLoadChartColors.eb_stroke, // Border color of the dots
                strokeWidth: 1, // Border width of the dots
                hover: {
                    size: 6, // Increase dot size on hover
                }
            },
            colors: [
                pithLoadChartColors.expected,
                pithLoadChartColors.actual,
                pithLoadChartColors.eb_units
            ],
            xaxis: {
                categories: categories,
                labels: {
                    rotate: rotateValue,
                    rotateAlways: rotateAlways,
                    style: {
                        fontSize: "11px",
                    },
                },
            },
            dataLabels: {
                enabled: false,
                enabledOnSeries: [0, 1],
                style: {
                    fontSize: '11px',
                },
                background: {
                    enabled: false,
                }
            },
            yaxis: [
                {
                    seriesName: "Estimated",
                    min: 0,
                    max: maxValue,
                    decimalsInFloat: 0,
                    title: {
                        text: "cft",
                        style: {
                            fontWeight: 500,
                        }
                    },
                    labels: {
                        formatter: function(val) {
                            return typeof formatToIndianNumberWithHover === 'function' ? formatToIndianNumberWithHover(val) : val;
                        }
                    },
                    tickAmount: 4, // Reduces the number of gridlines to 4
                },
                {
                    seriesName: "Actuals",
                    show: false,
                    min: 0,
                    max: maxValue,
                    decimalsInFloat: 0,
                    labels: {
                        formatter: function(val) {
                            return typeof formatToIndianNumberWithHover === 'function' ? formatToIndianNumberWithHover(val) : val;
                        }
                    },
                },
                {
                    seriesName: "EB",
                    opposite: true,
                    min: 0,
                    decimalsInFloat: 1,
                    title: {
                        text: "EB Cost",
                        style: {
                            fontWeight: 500,
                        }
                    },
                    labels: {
                        formatter: function(val) {
                            return typeof formatToIndianNumberNormal === 'function' ? formatToIndianNumberNormal(val) : val;
                        },
                    },
                },
            ],
            legend: {
                show: false,
            },
            tooltip: {
                shared: true,
                intersect: false,
                fixed: {
                    enabled: false,
                    position: 'topLeft', // topRight, topLeft, bottomRight, bottomLeft
                    offsetY: 30,
                    offsetX: 60
                },
                y: [{
                        formatter: function(val) {
                            return typeof formatToIndianNumberNormal === 'function' ? formatToIndianNumberNormal(val, 2) : val;
                        },
                    },
                    {
                        formatter: function(val) {
                            return typeof formatToIndianNumberNormal === 'function' ? formatToIndianNumberNormal(val, 2) : val;
                        },
                    },
                    {
                        formatter: function(val) {
                            return typeof formatToIndianNumberNormal === 'function' ? '₹' + formatToIndianNumberNormal(val, 0) : '₹' + val;
                        },
                    }
                ],
            },
        };

        pithLoadsChart = new ApexCharts(document.querySelector("#pith-loads-chart"), options);
        pithLoadsChart.render();

        if (!showActuals) {
            // Fully suppress the Actuals series — bars, tooltip row, and all — not just its data
            pithLoadsChart.hideSeries("Actuals");
        }
    }
</script>
