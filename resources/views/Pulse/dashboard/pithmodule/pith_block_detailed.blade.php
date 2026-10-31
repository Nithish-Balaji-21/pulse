<x-app-layout>
    <x-slot name="header">
        <div class="flex place-content-between">
            <div class="flex items-center space-x-2">
                <h1 class="font-semibold text-lg">Pith Blocks Production</h1>
            </div>

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

            {{-- Back Button --}}
            <div>
                <button onclick="history.back()" class="cancel-btn flex items-center text-sm">
                    <i class="fa-solid fa-chevron-left fa-xs"></i>&nbsp;
                    <span class="text-sm"> Back</span>
                </button>
            </div>
        </div>
    </x-slot>

    <div class="p-4 space-y-4">
        <div class="flex space-x-4">
            <!-- Monthly Chart -->
            <div class="w-72 border p-2 bg-[#fafff0]">
                <div id="pithMonthlyChart"></div>
            </div>

            <!-- Daily Chart -->
            <div class="flex-1 border p-2">
                <div id="pithDailyChart"></div>
            </div>
        </div>
    </div>

    @push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            let currentMonthYear = null;
            let initialYear, initialMonth;

            const monthlyData = @json($dashboardData['monthly_blocks'] ?? []);
            const dailyData = @json($dashboardData['daily_blocks'] ?? []);

            // Initialize currentYear from currentYearDisplay
            let currentYear = parseInt(document.getElementById('currentYearDisplay').textContent, 10);

            const installedYear = {{ $installedYear }};
            const currentYearDisplay = document.getElementById('currentYearDisplay');
            const leftYearButton = document.getElementById('leftYearButton');
            const rightYearButton = document.getElementById('rightYearButton');

            function updateYearButtons(year) {
                leftYearButton.style.visibility = year > installedYear ? 'visible' : 'hidden';
                rightYearButton.style.visibility = year < currentYear ? 'visible' : 'hidden';
            }

            function summarizeMonthly(year) {
                const filtered = monthlyData.filter(d => d.year === year);
                const categories = filtered.map(d =>
                    moment(`${d.year}-${d.month}-01`, 'YYYY-MM-DD').format('MMM YYYY')
                );
                const series = [{
                    name: 'Blocks',
                    data: filtered.map(d => d.blocks)
                }];
                return { categories, series };
            }

            // Function to change year
            window.changeYear = function(direction) {
                const newYear = parseInt(currentYearDisplay.textContent, 10) + direction;
                currentYearDisplay.textContent = newYear;
                updateYearButtons(newYear);
                updateYearFilter(newYear);
            };

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
                        title: { text: "Daily Blocks Production — No Data" }
                    });
                }
            };

            // Initialize Monthly Chart
            const monthlyChart = new ApexCharts(document.querySelector('#pithMonthlyChart'), {
                chart: {
                    type: 'bar',
                    height: 500,
                    events: {
                        dataPointSelection: (event, chartContext, { dataPointIndex }) => {
                            const { year, month } = monthlyData[dataPointIndex];
                            currentMonthYear = { year, month };
                            updateDailyChart(year, month);
                        }
                    }
                },
                plotOptions: { bar: { horizontal: true } },
                dataLabels: { enabled: true },
                tooltip: {
                    enabled: true,
                    custom: function({ series, seriesIndex, dataPointIndex, w}) {
                        return '';
                    }
                },
                series: summarizeMonthly(currentYear).series,
                xaxis: {
                    categories: summarizeMonthly(currentYear).categories,
                    labels: { show: false },
                    type: 'category'
                },
                title: {
                    text: "Monthly Blocks Production",
                    align: "left",
                    style: { fontSize: "16px", fontWeight: "600", color: "#1F2937" }
                },
                colors: ['#28a745']
            });
            monthlyChart.render();

            const dailyChart = new ApexCharts(document.querySelector('#pithDailyChart'), {
                chart: { type: 'bar', height: 500, zoom: { enabled: false } },
                dataLabels: { enabled: true },
                tooltip: { enabled: true },
                series: [],
                xaxis: {
                    categories: [],
                    labels: { rotate: -45, rotateAlways: true },
                    type: 'category'
                },
                title: {
                    text: "Daily Blocks Production",
                    align: "left",
                    style: { fontSize: "16px", fontWeight: "600", color: "#1F2937" }
                },
                colors: ['#28a745']
            });
            dailyChart.render();

            function updateDailyChart(year, month) {
                const prefix = `${year}-${String(month).padStart(2, '0')}`;
                const filtered = dailyData.filter(d => d.day.startsWith(prefix));
                const categories = filtered.map(d =>
                    moment(d.day, 'YYYY-MM-DD').format('D MMM')
                );
                const series = [{
                    name: 'Blocks',
                    data: filtered.map(d => d.blocks)
                }];
                dailyChart.updateOptions({
                    series,
                    xaxis: {
                        categories,
                        type: 'category'
                    },
                    title: { text: `Daily Blocks — ${moment(prefix + '-01').format('MMMM YYYY')}` }
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

            updateYearButtons(currentYear);
        });
    </script>
    @endpush
</x-app-layout>