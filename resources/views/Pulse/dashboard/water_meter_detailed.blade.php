<x-app-layout>
    <div class="w-full py-2 ps-2 sm:ps-3 lg:ps-4 pe-3">
        <div class="flex place-content-between">
            <div class="flex items-center space-x-2">
                <h1 class="font-semibold text-lg">Water Meter Consumption</h1>
            </div>

                <!-- Year Navigation -->
                <div class="flex items-center gap-x-4">
                    <i
                        class="fa-solid fa-chevron-left text-xs text-blue-500 cursor-pointer"
                        onclick="changeYear(-1)"
                        id="leftYearButton"
                    ></i>
                    <span id="currentYearDisplay" class="font-semibold text-lg text-blue-500">{{ now()->year }}</span>
                    <i
                        class="fa-solid fa-chevron-right text-xs text-blue-500 cursor-pointer"
                        onclick="changeYear(1)"
                        id="rightYearButton"
                    ></i>
                </div>

            {{-- Back Button --}}
            <div>
                <button onclick="history.back()" class="cancel-btn flex items-center text-sm">
                    <i class="fa-solid fa-chevron-left fa-xs"></i>&nbsp;
                    <span class="text-sm"> Back</span>
                </button>
            </div>
        </div>
    </div>

    <div class="p-4 space-y-4">
        <div class="flex space-x-4">
            <!-- Monthly Chart -->
            <div class="w-72 border p-2 bg-[#f0faff]">
                <div id="WaterMonthlyChart" class="h-full"></div>
                <div class="flex -mt-4">
                    <div class="w-full grid grid-cols-1 place-items-center">
                        <div class="flex items-center gap-2">
                            <div class="h-2 w-6 rounded bg-[#0077be]"></div>
                            <div class="text-left">
                                <p class="text-left text-xs text-gray-700 ml-0.5">Actuals</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex-1">
                <!-- Daily Chart -->
                <div class="flex-1 border p-2">
                    <div id="WaterDailyChart" class="w-full"></div>
                    <div class="flex justify-center">
                        <div class="grid grid-cols-1 place-items-center">
                            {{-- <div class="flex items-center gap-2">
                                <div class="h-2 w-6 rounded bg-[#0077be]"></div>
                                <div class="text-left">
                                    <p class="text-left text-xs text-gray-700 ml-0.5">Actuals</p>
                                </div>
                            </div> --}}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            let currentMonthYear = null;
            let initialYear, initialMonth;

            const monthlyData = @json($dashboardData['monthly_water_meter'] ?? []);
            const dailyData = @json($dashboardData['daily_water_meter'] ?? []);

            let currentYear = parseInt(document.getElementById('currentYearDisplay').textContent, 10);

            const installedYear = {{ $installedYear }};
            const currentYearDisplay = document.getElementById('currentYearDisplay');
            const leftYearButton = document.getElementById('leftYearButton');
            const rightYearButton = document.getElementById('rightYearButton');

            function updateYearButtons(year) {
                leftYearButton.style.visibility = year > installedYear ? 'visible' : 'hidden';
                rightYearButton.style.visibility = year < currentYear ? 'visible' : 'hidden';
            }

            window.changeYear = function(direction) {
                const newYear = parseInt(currentYearDisplay.textContent, 10) + direction;
                currentYearDisplay.textContent = newYear;
                updateYearButtons(newYear);
                updateYearFilter(newYear);
            };

            updateYearButtons(currentYear);

            function summarizeMonthly(year) {
                const filtered = monthlyData.filter(d => d.year === year);
                const categories = filtered.map(d =>
                    moment(`${d.year}-${d.month}-01`, 'YYYY-MM-DD').format('MMM YYYY')
                );
                const series = [{
                    name: 'Actuals',
                    data: filtered.map(d => Math.round(d.water_consumption))
                }];
                return { categories, series };
            }

            // Update charts based on year filter
            window.updateYearFilter = function(year) {
                currentYear = parseInt(year, 10);
                currentMonthYear = null;

                const { categories, series } = summarizeMonthly(currentYear);
                monthlyChart.updateOptions({
                    series,
                    xaxis: { categories }
                });

                if (categories.length) {
                    const [m, y] = categories[categories.length - 1].split(' ');
                    currentMonthYear = {
                        year: parseInt(y, 10),
                        month: parseInt(moment().month(m).format('M'), 10)
                    };
                    updateDailyChart(currentMonthYear.year, currentMonthYear.month);
                } else {
                    dailyChart.updateOptions({
                        series: [{ data: [] }],
                        xaxis: { categories: [] },
                        title: { text: "Daily Water Consumption — No Data" }
                    });
                }
            };

            // Initialize Monthly Chart
            const monthlyChart = new ApexCharts(document.querySelector('#WaterMonthlyChart'), {
                chart: {
                    type: 'bar',
                    height: 450,
                    events: {
                        dataPointSelection: (event, chartContext, { dataPointIndex }) => {
                            const { year, month } = monthlyData[dataPointIndex];
                            currentMonthYear = { year, month };
                            updateDailyChart(year, month);
                        }
                    }
                },
                plotOptions: { bar: { horizontal: true } },
                dataLabels: {
                    enabled: true,
                    formatter: function (val) {
                        if (val >= 10000000) return (val / 10000000).toFixed(1) + 'C';
                        if (val >= 100000) return (val / 100000).toFixed(1) + 'L';
                        if (val >= 1000) return (val / 1000).toFixed(1) + 'k';
                        return val;
                    }
                },
                tooltip: {
                    enabled: true,
                    custom: function({ series, seriesIndex, dataPointIndex, w }) {
                        return '';
                    }
                },
                series: summarizeMonthly(currentYear).series,
                xaxis: { categories: summarizeMonthly(currentYear).categories, labels: { show: false } },
                title: {
                    text: "Monthly Water Consumption",
                    align: "left",
                    style: { fontSize: "16px", fontWeight: "600", color: "#1F2937" }
                },
                colors: ['#0077be']
            });
            monthlyChart.render();

            const dailyChart = new ApexCharts(document.querySelector('#WaterDailyChart'), {
                chart: { type: 'area', height: 450, zoom: { enabled: false } },
                dataLabels: { enabled: false },
                tooltip: { enabled: true },
                series: [],
                xaxis: { categories: [], labels: { rotate: -45, rotateAlways: true } },
                title: {
                    text: "Daily Water Consumption",
                    align: "left",
                    style: { fontSize: "16px", fontWeight: "600", color: "#1F2937" }
                },
                colors: ['#0077be']
            });
            dailyChart.render();

            function updateDailyChart(year, month) {
                const prefix = `${year}-${String(month).padStart(2, '0')}`;
                const filtered = dailyData.filter(d => d.date.startsWith(prefix));
                const categories = filtered.map(d =>
                    moment(d.date, 'YYYY-MM-DD').format('D MMM')
                );
                const series = [{
                    name: 'Litres Consumed',
                    data: filtered.map(d => Math.round(d.water_consumption))
                }];
                dailyChart.updateOptions({
                    series,
                    xaxis: { categories },
                    title: { text: `Daily Water Consumption — ${moment(prefix + '-01').format('MMMM YYYY')}` }
                });
            }

            const initCats = summarizeMonthly(currentYear).categories;
            if (initCats.length) {
                const [m, y] = initCats[initCats.length - 1].split(' ');
                currentMonthYear = {
                    year: parseInt(y, 10),
                    month: parseInt(moment().month(m).format('M'), 10)
                };
            } else {
                const lm = moment().subtract(1, 'month');
                currentMonthYear = { year: lm.year(), month: lm.month() + 1 };
            }
            updateDailyChart(currentMonthYear.year, currentMonthYear.month);
        });
    </script>
    @endpush
</x-app-layout>