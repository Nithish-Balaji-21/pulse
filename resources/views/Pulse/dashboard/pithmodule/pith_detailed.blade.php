<x-app-layout>
    <div class="w-full py-2 ps-2 sm:ps-3 lg:ps-4 pe-3">
        <div class="flex place-content-between">
            <div class="flex items-center space-x-2">
                <h1 class="font-semibold text-lg">Pith CFT Production</h1>
            </div>

            <!-- Shift Radio Buttons -->
            <div class="flex flex-col items-center justify-center">
                <div>
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
                </div>

                <div class="flex items-center justify-center space-x-4">
                    <label class="flex items-center gap-1">
                        <input type="radio" name="shiftFilter" value="all" checked onchange="updateShiftFilter('all')" class="size-3">
                        All Shifts
                    </label>
                    @foreach (collect($dashboardData['daily_pith']['daily'] ?? [])->pluck('shift')->unique() as $shift)
                        <label class="flex items-center gap-1">
                            <input type="radio" name="shiftFilter" value="{{ $shift }}" onchange="updateShiftFilter('{{ $shift }}')" class="size-3">
                            {{ $shift }}
                        </label>
                    @endforeach
                </div>
            </div>

            <!-- Back Button -->
            <div>
                <button onclick="history.back()" class="cancel-btn flex items-center text-sm">
                    <i class="fa-solid fa-chevron-left fa-xs"></i>&nbsp;
                    <span class="text-sm">Back</span>
                </button>
            </div>
        </div>
    </div>

    <div class="px-4 py-2 space-y-4">
        <div class="flex space-x-4">
            <!-- Monthly Chart -->
            <div class="w-72 border p-2 bg-[#ebefff]">
                <div id="pithMonthlyChart" class="h-full"></div>
                <div class="flex -mt-4">
                    <div class="w-full grid grid-cols-2 place-items-center">
                        <div class="flex items-center gap-2">
                            <div class="h-2 w-6 rounded bg-[#3B82F6]"></div>
                            <div class="text-left">
                                <p class="text-left text-xs text-gray-700 ml-0.5">Estimated</p>
                            </div>
                        </div>
                        @if (auth()->user()->currentTeam->showActuals())
                        <div class="flex items-center gap-2">
                            <div class="h-2 w-6 rounded bg-[#28a745]"></div>
                            <div class="text-left">
                                <p class="text-left text-xs text-gray-700 ml-0.5">Actuals</p>
                            </div>
                        </div>
                        @endif
                    </div>
                </div>
            </div>

            <!-- Daily Chart -->
            <div class="flex-1 border p-2 h-full">
                <div id="pithDailyChart" class="h-full w-full"></div>
                <div class="flex justify-center -mt-4">
                    <div class="place-items-center">
                        <div class="flex items-center gap-2">
                            <img src="/images/line-icon.png" alt="" height="2" width="20">
                            <div class="text-left">
                                <p id="ebUnitsDisplay" class="text-left text-sm text-gray-700 ml-0.5">
                                    EB Units: {{ \App\Services\NumberFormatterService::formatToIndianFormat($dashboardData['monthly_pith']['totals']->last()['eb_units'] ?? 0, false) }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const showActuals = {{ auth()->user()->currentTeam->showActuals() ? 'true' : 'false' }};

        // Global variables
        let currentShift = 'all';
        let currentMonthYear = null;

        const monthlyData = @json($dashboardData['monthly_pith']['monthly'] ?? []);
        const dailyData = @json($dashboardData['daily_pith']['daily'] ?? []);

        // Get initial year from display
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

        // Function to summarize monthly data
        function summarizeMonthly(year, shift = 'all') {
            const filtered = monthlyData.filter(d => d.year === year && (shift === 'all' || d.shift === shift));
            const sums = {};

            filtered.forEach(d => {
                const label = moment(`${d.year}-${d.month}-01`, 'YYYY-MM-DD').format('MMM YYYY');
                sums[label] = sums[label] || { produced: 0, expected: 0 };
                sums[label].produced += Math.round(d.loads_produced);
                sums[label].expected += Math.round(d.loads_expected);
            });

            const categories = Object.keys(sums);
            return {
                categories,
                series: [
                    { name: 'Actuals', data: showActuals ? categories.map(l => sums[l].produced) : categories.map(() => null) },
                    { name: 'Estimated', data: categories.map(l => sums[l].expected) }
                ]
            };
        }

        // Initialize Monthly Chart
        const monthlyChart = new ApexCharts(document.querySelector('#pithMonthlyChart'), {
            chart: {
                type: 'bar',
                height: 450,
                events: {
                    click: (e, chartCtx, { dataPointIndex }) => {
                        if (dataPointIndex < 0) return;

                        const cats = chartCtx.w.config.xaxis.categories;
                        const label = cats[dataPointIndex];
                        const [mon, yr] = label.split(' ');
                        const m = parseInt(moment().month(mon).format('M'), 10);

                        currentMonthYear = { year: parseInt(yr, 10), month: m };
                        updateDailyChart(currentMonthYear.year, currentMonthYear.month, currentShift);
                    }
                }
            },
            plotOptions: {
                bar: {
                    horizontal: true,
                    dataLabels: { position: 'bottom' }
                }
            },
            dataLabels: {
                enabled: true,
                offsetX: 10,
                style: {
                    background: 'transparent',
                    dropShadow: false
                }
            },
            tooltip: {
                shared: true,
                intersect: false,
                fixed: {
                    enabled: true,
                    position: 'topLeft'
                },
                y: {
                    formatter: (val, opts) => {
                        const arr = opts.w.config.series[opts.seriesIndex].data;
                        return arr[opts.dataPointIndex];
                    }
                },
                custom: function({ series, seriesIndex, dataPointIndex, w }) {
                    return '';
                }
            },
            series: summarizeMonthly(currentYear).series,
            xaxis: {
                categories: summarizeMonthly(currentYear).categories,
                labels: { show: false }
            },
            legend: { show: false },
            title: {
                text: "Monthly Pith Production",
                align: "left",
                style: {
                    fontSize: "16px",
                    fontWeight: "600",
                    color: "#1F2937",
                    padding: "10px"
                }
            },
            colors: ['#28a745', '#3B82F6']
        });
        monthlyChart.render();

        // Initialize Daily Chart
        const dailyChart = new ApexCharts(document.querySelector('#pithDailyChart'), {
            series: [
                { name: "Estimated", type: "column", data: [] },
                { name: "Actuals", type: "column", data: [] },
                { name: "EB", type: "line", data: [] }
            ],
            chart: {
                height: 450,
                toolbar: {
                    export: {
                        csv: { headerCategory: 'Date' }
                    }
                },
                zoom: { enabled: false }
            },
            plotOptions: {
                bar: {
                    columnWidth: '55%',
                    dataLabels: { position: 'top' }
                }
            },
            stroke: { width: [1, 1, 3] },
            markers: {
                size: 4,
                strokeColors: '#FFFFFF',
                strokeWidth: 1,
                hover: { size: 6 }
            },
            colors: ["#3B82F6", "#28a745", "#dc3545"],
            xaxis: {
                categories: [],
                labels: {
                    rotate: -45,
                    rotateAlways: true,
                    style: { fontSize: "11px" }
                }
            },
            dataLabels: {
                enabled: false,
                enabledOnSeries: [0, 1],
                style: { fontSize: '11px' }
            },
            yaxis: [
                {
                    seriesName: "Estimated",
                    min: 0,
                    title: {
                        text: "cft",
                        style: { fontWeight: 500 }
                    },
                    tickAmount: 4
                },
                {
                    seriesName: "Actuals",
                    show: false,
                    min: 0
                },
                {
                    seriesName: "EB",
                    opposite: true,
                    min: 0,
                    title: {
                        text: "EB Units",
                        style: { fontWeight: 500 }
                    },
                    labels: {
                        formatter: val => formatToShortNumbers(val, 0)
                    }
                }
            ],
            legend: { show: false },
            title: {
                text: "Daily Pith Production",
                align: "left",
                style: {
                    fontSize: "16px",
                    fontWeight: "600",
                    color: "#1F2937",
                    padding: "10px"
                }
            },
            tooltip: {
                shared: true,
                intersect: false,
                y: [
                    { formatter: v => v + " cft" },
                    { formatter: v => v + " cft" },
                    { formatter: v => v + " units" }
                ]
            }
        });
        dailyChart.render();

        // Update daily chart data
        function updateDailyChart(year, month, shift = 'all') {
            const prefix = `${year}-${String(month).padStart(2, '0')}`;
            const filt = dailyData.filter(d =>
                d.day.startsWith(prefix) &&
                (shift === 'all' || d.shift === shift)
            );

            const sums = {};
            filt.forEach(d => {
                const lbl = moment(d.day, 'YYYY-MM-DD').format('MMM D');
                sums[lbl] = sums[lbl] || { prod: 0, exp: 0, eb: 0 };
                sums[lbl].prod += Math.round(d.loads_produced);
                sums[lbl].exp += Math.round(d.loads_expected);
                sums[lbl].eb += Math.round(d.eb_units);
            });

            const cats = Object.keys(sums);
            const series = [
                { data: cats.map(l => sums[l].exp) },
                { data: showActuals ? cats.map(l => sums[l].prod) : cats.map(() => null) },
                { data: cats.map(l => sums[l].eb) }
            ];

            const totalEb = filt.reduce((s, d) => s + Math.round(d.eb_units), 0);
            document.getElementById('ebUnitsDisplay').textContent =
                `EB Units: ${formatToIndianRupees(totalEb)}`;

            dailyChart.updateOptions({
                series,
                xaxis: { categories: cats },
                title: { text: `Daily Pith Production — ${moment(prefix + '-01').format('MMMM YYYY')}` }
            });
        }

        // Shift filter handler
        window.updateShiftFilter = function(shift) {
            currentShift = shift;
            const { categories, series } = summarizeMonthly(currentYear, shift);

            if (!currentMonthYear && categories.length) {
                const [m, y] = categories[categories.length - 1].split(' ');
                currentMonthYear = {
                    year: parseInt(y, 10),
                    month: parseInt(moment().month(m).format('M'), 10)
                };
            }

            monthlyChart.updateOptions({
                series,
                xaxis: { categories }
            });

            if (currentMonthYear) {
                updateDailyChart(currentMonthYear.year, currentMonthYear.month, currentShift);
            }
        };

        // Year filter handler
        window.updateYearFilter = function(year) {
            currentYear = parseInt(year, 10);
            currentMonthYear = null;

            const { categories, series } = summarizeMonthly(currentYear, currentShift);
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
                updateDailyChart(currentMonthYear.year, currentMonthYear.month, currentShift);
            } else {
                dailyChart.updateOptions({
                    series: [{ data: [] }, { data: [] }, { data: [] }],
                    xaxis: { categories: [] },
                    title: { text: "Daily Pith Production — No Data" }
                });
                document.getElementById('ebUnitsDisplay').textContent = 'EB Units: 0';
            }
        };

        // Initial load
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
        updateDailyChart(currentMonthYear.year, currentMonthYear.month, currentShift);
    });

    function formatToIndianRupees(value) {
        const parts = value.toString().split(".");
        const integerPart = parts[0];
        const decimalPart = parts[1] ? "." + parts[1] : "";
        const lastThree = integerPart.slice(-3);
        const otherNumbers = integerPart.slice(0, -3);
        const formatted = otherNumbers.replace(/\B(?=(\d{2})+(?!\d))/g, ",") + (otherNumbers ? "," : "") + lastThree + decimalPart;
        return formatted;
    }
    </script>
    @endpush
</x-app-layout>