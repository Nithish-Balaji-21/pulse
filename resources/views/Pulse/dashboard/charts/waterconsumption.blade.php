<!-- Water Meter consumption Chart -->
<div class="h-full bg-white rounded shadow-[0_0_10px_rgba(0,0,0,0.15)] px-4 pt-4">

    <div class="flex justify-between items-center mb-2">
        <h3 class="text-base font-semibold text-gray-800">Water Consumed</h3>

        <div id="water-consumption-legend" class="flex items-center space-x-4 text-xs font-medium text-gray-700 mr-12">
            <!-- Legend items will be injected here by JavaScript -->
        </div>
    </div>

    <hr class="border-t border-gray-400 my-2">
    <div id="water-consumption-chart" style="display: none;"></div>
    <p id="water-consumption-empty" class="hidden text-sm text-gray-700 mt-2 pb-4">
        No data available for the selected duration.
    </p>
</div>

<script>
    const waterConsumptionChartColors = @json(config('colors.water_consumption_charts'));
    var waterConsumptionChart = null;

    function renderWaterConsumptionChart(waterMeter)
    {
        // Destroy previous chart if exists
        if (waterConsumptionChart) {
            waterConsumptionChart.destroy();
            waterConsumptionChart = null;
        }

        const chartContainer = document.getElementById('water-consumption-chart');
        const emptyMessage = document.getElementById('water-consumption-empty');
        const legendDisplay = document.getElementById('water-consumption-legend');

        // Extract datasets
        const normalData = waterMeter.normal.data || [];
        const huskData = waterMeter.husk.data || [];

        // Check for any consumed value in either series
        const hasNormalValues = normalData.some(item => item.litres > 0);
        const hasHuskValues = huskData.some(item => item.litres > 0);
        const hasValues = hasNormalValues || hasHuskValues;

        if (!hasValues) {
            // Hide chart and legend, show empty message
            chartContainer.innerHTML = '';
            chartContainer.style.display = 'none';
            legendDisplay.innerHTML = '';
            legendDisplay.classList.add('hidden');
            emptyMessage.classList.remove('hidden');
            return;
        }

        // --- Data Preparation ---
        emptyMessage.classList.add('hidden');
        chartContainer.style.display = 'block';
        legendDisplay.classList.remove('hidden');

        const normalColor = waterConsumptionChartColors.base;
        const huskColor = waterConsumptionChartColors.husk;
        const seriesColors = [normalColor, huskColor];

        // --- Metrics & Legend Display Update ---
        const normalTotal = formatToIndianNumberNormal(waterMeter.normal.total_litres);
        const huskTotal = formatToIndianNumberNormal(waterMeter.husk.total_litres);

        // Construct the custom legend HTML
        legendDisplay.innerHTML = `
            <!-- Normal Water Legend Item -->
            <span class="flex items-center">
                <span class="w-2.5 h-2.5 rounded-full mr-1 shrink-0" style="background-color: ${normalColor};"></span>
                Total Water (<span class="font-semibold tabular-nums">${normalTotal}</span> ltr)
            </span>
            <!-- Husk Water Legend Item -->
            <span class="flex items-center">
                <span class="w-2.5 h-2.5 rounded-full mr-1 shrink-0" style="background-color: ${huskColor};"></span>
                Husk Water (<span class="font-semibold tabular-nums">${huskTotal}</span> ltr)
            </span>
        `;

        const categories = normalData.map(item => item.period);

        let normalLitres = normalData.map(item => item.litres);
        let huskLitres = huskData.map(item => item.litres);

        if (waterMeter.normal.total_litres === 0) {
            normalLitres = Array(categories.length).fill(0);
        }
        if (waterMeter.husk.total_litres === 0) {
            huskLitres = Array(categories.length).fill(0);
        }

        // Find max value across both series for the Y-axis scale
        const allLitres = normalLitres.concat(huskLitres);
        const maxValue = roundUpToNearest(Math.max(...allLitres, 0)); // Ensure min is 0


        // --- Chart Configuration ---
        var options = {
            series: [
                {
                    name: `Total Water`,
                    data: normalLitres,
                },
                {
                    name: `Husk Water`,
                    data: huskLitres,
                },
            ],
            chart: {
                height: 250,
                type: "area",
                toolbar: {
                    export: {
                        csv: {
                            headerCategory: 'Date',
                        }
                    }
                },
                zoom: {
                    enabled: false
                },
            },
            stroke: {
                curve: 'smooth',
                width: 3,
            },
            colors: seriesColors,
            fill: {
                type: ['gradient', 'gradient'],
                gradient: {
                    shade: 'light',
                    type: 'vertical',
                    shadeIntensity: 0.5,
                    opacityFrom: 0.8,
                    opacityTo: 0.2,
                    stops: [0, 100]
                }
            },
            markers: {
                size: 5,
                strokeWidth: 2,
                hover: {
                    size: 7,
                }
            },
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
            yaxis: {
                min: 0,
                max: maxValue,
                decimalsInFloat: 0,
                title: {
                    text: "Litres",
                    style: {
                        fontWeight: 500,
                    }
                },
                tickAmount: 4,
            },
            legend: {
                show: false,
            },
            tooltip: {
                shared: true,
                intersect: false,
                theme: 'light',
                style: {
                    fontSize: '12px',
                },
                y: {
                    formatter: function(val) {
                        return formatToIndianNumberNormal(val) + " litres";
                    },
                },
            },
            dataLabels: {
                enabled: false,
            },
        };

        waterConsumptionChart = new ApexCharts(document.querySelector("#water-consumption-chart"), options);
        waterConsumptionChart.render();
    }
</script>