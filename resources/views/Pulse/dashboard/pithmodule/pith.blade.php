<x-app-layout>

    <link rel="stylesheet" href="@assetVersion('css/pith-dashboard.css')">
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
                <div x-data="{ init() { initRangeSelector(); } }" class="flex flex-col items-end grow min-w-0">
                    @include('pulse::dashboard.date-range-selector')

                    {{-- Superadmin only, and only while yesterday is the selected period —
                         the rerun acts on yesterday whatever else is on screen. --}}
                    @if (Auth::user()->isSuperadmin() && $shiftStartWithBuffer && now()->gt($shiftStartWithBuffer) && ! app()->environment(['demo', 'local']))
                        <div class="pulse-yesterday-only mt-1.5 text-xs text-gray-600 hidden">
                            Yesterday data reconciliation issue?
                            <a href="javascript:void(0)" id="runSchedulerBtn" class="text-[#83211b] font-semibold hover:underline">
                                Rerun reconciliation
                            </a>
                        </div>
                    @endif
                </div>
            </div>

            @if($shiftStartWithBuffer && now()->lte($shiftStartWithBuffer))
                <div id="yesterdaySummaryMsg" class="mt-2 w-full text-center bg-blue-100 text-blue-800 px-2 py-1 rounded shadow-xs hidden">
                    Please wait until {{ \Carbon\Carbon::parse($shiftStartWithBuffer)->format('g:i a') }} to get yesterday's summary.
                </div>
            @endif
        </div>
    </x-slot>

    <div class="w-full py-0 ps-2 sm:ps-3 lg:ps-4 pe-3">
        @php
            $team = auth()->user()->currentTeam;
            $hasExpenseReport = app(\App\Services\ExpenseConnection::class)->supports($team);

            // Define all possible cards with their respective team flags
            $allCards = [
                ['flag' => 'has_pith_module',         'id' => 'totalPithLoads',         'label' => 'Pith Produced',          'icon' => 'pith-loads-icon.svg'],
                ['flag' => 'has_pith_module_bales',   'id' => 'totalFibreBales',        'label' => 'Fibre bales',        'icon' => 'fibre-bales-icon.svg'],
                ['flag' => 'has_pith_module_blocks',  'id' => 'totalPithBlocks',        'label' => 'Pith blocks',        'icon' => 'pith-blocks-icon.svg'],
                ['flag' => 'has_water_meter_module',  'id' => 'totalWaterConsumption',  'label' => 'Water consumed',     'icon' => 'water-consumption-icon.svg'],
                ['flag' => 'hasWaterTanks',           'id' => 'tankCapacity',           'label' => 'Water Available',    'icon' => 'tank-capacity-icon.svg'],
                ['flag' => null,                      'id' => 'totalExpenses',          'label' => $hasExpenseReport ? 'Total Expense' : 'Staff Attendance',  'icon' => 'expenses-icon.svg'],
            ];

            // Filter cards based on active modules or configured tanks
            $summaryCards = collect($allCards)->filter(function ($card) use ($team, $hasWaterTanks) {
                if (!$card['flag']) return true;
                if ($card['flag'] === 'hasWaterTanks') return (bool)($hasWaterTanks ?? false);
                return $team && $team->{$card['flag']};
            });
        @endphp

        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 mb-3" id="summary-cards">
            @forelse ($summaryCards as $card)
                @if ($card['id'] === 'totalExpenses' && $hasExpenseReport)
                <a id="expense-summary-card" href="{{ route('reports.expenses', ['team' => $team->id]) }}" data-url="{{ route('expenses.summary', ['team' => $team->id]) }}" class="summary-card-item bg-white shadow-xs hover:shadow-md transition-shadow duration-200 p-4 flex justify-between items-start min-h-[92px] border-2 border-gray-300 rounded-xl" aria-label="Total Expense — view expense report">
                    <div class="flex flex-col justify-start flex-1 pr-3 min-w-0 pt-0.5">
                        <div class="caps-label leading-tight">Total Expense</div>
                        <div id="totalExpenses" aria-live="polite" class="text-2xl font-semibold tabular-nums tracking-tight text-gray-900 leading-tight mt-1">—</div>
                        <span data-expense-breakdown role="img" aria-label="Expense breakdown loading" style="display:flex;height:4px;width:100%;max-width:180px;margin-top:10px;border-radius:999px;overflow:hidden;background:#edf0f4">
                            <span data-expense-segment="hourly_cost" style="width:0;background:#075e50"></span>
                            <span data-expense-segment="activity_cost" style="width:0;background:#b68017"></span>
                            <span data-expense-segment="materials_vehicles_cost" style="width:0;background:#557fa8"></span>
                        </span>
                        <span class="mt-2 inline-flex items-center gap-1.5 text-xs font-semibold text-[#83211b]">View report <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M12 5l7 7-7 7" /></svg></span>
                    </div>
                    <div class="shrink-0 pt-3 -mr-1"><img src="{{ asset('images/expenses-icon.svg') }}" alt="" class="w-full h-full object-contain"></div>
                </a>
                @else
                <div class="summary-card-item {{ $card['id'] === 'totalPithLoads' ? 'pith-load-card bg-white shadow-xs cursor-default' : 'bg-white shadow-xs hover:shadow-md transition-shadow duration-200' }} p-4 flex justify-between items-start min-h-[92px] border-2 border-gray-300 rounded-xl">
                    <div class="flex flex-col justify-start flex-1 pr-3 min-w-0 pt-0.5">
                        <div class="caps-label leading-tight">{{ $card['label'] }}</div>
                        <div id="{{ $card['id'] }}" class="text-2xl font-semibold tabular-nums tracking-tight text-gray-900 leading-tight mt-1">—</div>
                    </div>
                    <div class="shrink-0 pt-3 -mr-1">
                        <img src="{{ asset('images/' . $card['icon']) }}" alt="{{ $card['label'] }} icon" class="w-full h-full object-contain">
                    </div>
                </div>
                @endif
            @empty
                <p class="text-gray-500 text-center col-span-6">No active modules found for this team.</p>
            @endforelse
        </div>

        @include('pulse::dashboard.expense-widget')

        <!-- Main content grid -->
        <div class="grid grid-cols-1 lg:grid-cols-4 gap-4 mb-4">
            <!-- Container for the two mutually exclusive 50% width charts-->
            <div class="lg:col-span-2">
                <!-- Hourly Machine States Chart (Visible on single day) -->
                @include('pulse::dashboard.charts.hourlymachinestates')

                <!-- Pith Loads Chart (Visible on date range) -->
                @include('pulse::dashboard.charts.pithloads')
            </div>

            <!-- Pith Loads by Shift Chart (25% width) -->
            <div class="lg:col-span-1">
                @include('pulse::dashboard.charts.pithloadsshift')
            </div>

            <!-- Device States Chart (25% width) -->
            <div class="lg:col-span-1">
                @include('pulse::dashboard.charts.devicestates')
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4 mb-4">

            @include('pulse::dashboard.charts.minibales')

            <!-- Pith Blocks Chart -->
            @if (auth()->user()->currentTeam && auth()->user()->currentTeam->has_pith_module_blocks)
                @include('pulse::dashboard.charts.pithblocks')
            @endif

            <!-- Water Meter Consumption Chart -->
            @if (auth()->user()->currentTeam && auth()->user()->currentTeam->has_water_meter_module)
                @include('pulse::dashboard.charts.waterconsumption')
            @endif

            <!-- Water Tank Capacity Chart -->
            @if (isset($hasWaterTanks) && $hasWaterTanks)
                @include('pulse::dashboard.charts.watertanklevel')
            @endif

            <!-- Attendance Chart -->
            @include('pulse::dashboard.attendance')
        </div>
    </div>

    <script src="@assetVersion('js/daterangeselector.js')"></script>
    <script>
        window.showActuals = {{ auth()->user()->currentTeam->showActuals() ? 'true' : 'false' }};

        // Formatter: full Indian-format number (e.g., 1,18,799)
        function formatToIndianNumberNormal(num, decimals = 0) {
            if (num === null || num === undefined || num === '' || Number.isNaN(Number(num))) return '—';
            const n = Number(num);
            const parts = (decimals > 0 ? n.toFixed(decimals) : Math.round(n).toString()).split('.');
            let intPart = parts[0];
            const fracPart = parts[1] ? '.' + parts[1] : '';
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
            return typeof formatToIndianNumber === 'function' ? formatToIndianNumber(num, false, false, false) : num;
        }
        // Helper to update summary cards
        function updateSummaryCards(data) {
            const pithSummaryNumFmt = (value) => {
                if (value === null || value === undefined || value === '') return '—';

                const parsed = Number(value);
                if (!Number.isFinite(parsed)) return '—';

                const displayValue = parsed > 99 ? Math.round(parsed) : parsed;
                const decimals = parsed > 99 ? 0 : (Number.isInteger(parsed) ? 0 : 1);

                return (typeof formatToIndianNumberWithHover === 'function')
                    ? formatToIndianNumberWithHover(displayValue, false)
                    : formatToIndianNumberWithHover(displayValue);
            };
            const summaryBarColors = {
                actual: @json(config('colors.pith_load_charts.actual')),
                expected: @json(config('colors.pith_load_charts.expected')),
            };

            // Small helper to update a DOM element safely (compact display + full-value hover tooltip)
            const setText = (id, val, suffix = '', useCompact = true) => {
                const $el = $(id);
                if (!(val || val === 0)) {
                    $el.attr('title', '');
                    $el.text('—');
                    return;
                }
                const full = (typeof formatToIndianNumberNormal === 'function')
                    ? formatToIndianNumberNormal(Number(val))
                    : ((typeof formatNumber === 'function') ? formatNumber(Number(val)) : String(val));
                const compact = useCompact
                    ? ((typeof formatToIndianNumberWithHover === 'function')
                        ? formatToIndianNumberWithHover(Number(val))
                        : ((typeof formatNumber === 'function') ? formatNumber(Number(val)) : String(val)))
                    : full;

                const display = compact + suffix;
                const title = full + suffix;
                $el.attr('title', title !== display ? title : '');
                $el.text(display);
            };

            const metricNumber = (value) => {
                if (value === null || value === undefined || value === '') return null;
                const parsed = Number(value);
                return Number.isFinite(parsed) ? parsed : null;
            };

            // Helper to render actual and expected values around one comparison bar
            const setComparisonBarMetrics = (id, actual, expected, labels = ['Actuals', 'Estimated']) => {
                const el = $(id);
                if ((actual === null || actual === undefined) && (expected === null || expected === undefined)) {
                    el.text('—');
                    el.attr('title', '');
                    return;
                }

                const actualText = (actual === null || actual === undefined) ? '—' : pithSummaryNumFmt(actual);
                const expectedText = (expected === null || expected === undefined) ? '—' : pithSummaryNumFmt(expected);
                const actualFull = (actual === null || actual === undefined) ? '—' : (typeof formatToIndianNumberNormal === 'function' ? formatToIndianNumberNormal(actual, 2) : actual);
                const expectedFull = (expected === null || expected === undefined) ? '—' : (typeof formatToIndianNumberNormal === 'function' ? formatToIndianNumberNormal(expected, 2) : expected);

                el.html(`
                    <div class="mt-1 w-full max-w-[220px]">
                        <div class="flex items-end justify-start gap-3">
                            <div class="flex flex-col items-center">
                                <span title="${escapeHtml(actualFull)}" class="text-2xl font-semibold tabular-nums tracking-tight text-gray-900 leading-tight">${actualText}</span>
                                <span class="text-xs text-gray-500 leading-tight mt-0.5">${labels[0]}</span>
                            </div>
                            <div class="flex flex-col items-center">
                                <span title="${escapeHtml(expectedFull)}" class="text-2xl font-semibold tabular-nums tracking-tight text-gray-900 leading-tight">${expectedText}</span>
                                <span class="text-xs text-gray-500 leading-tight mt-0.5">
                                    <span class="hidden sm:inline">${labels[1]}</span>
                                    <span class="inline sm:hidden">${labels[1] === 'Estimated' ? 'Est.' : labels[1]}</span>
                                </span>
                            </div>
                        </div>
                    </div>
                `);
                el.attr('title', '');
            };

            const setStackedMetrics = (id, actual, expected, labels = ['Actuals', 'Estimated']) => {
                setComparisonBarMetrics(id, actual, expected, labels);
            };

            const pith = data?.pithProduction || {};
            if (showActuals) {
                // pith.total => actual, pith.expected => expected
                setStackedMetrics('#totalPithLoads', pith.total, pith.expected, ['Actuals', 'Estimated']);
            } else {
                // Actuals off — show the full, uncompacted value rather than a K/L/Cr-shortened one
                setText('#totalPithLoads', pith.expected, '', false);
            }

            const fibre = data?.miniBaleProduction || {};
            const fibreActual = fibre.total_bales_produced ?? null;
            const fibreExpected = fibre.total_bales_expected ?? null;
            // Show only expected on the card
            setText('#totalFibreBales', fibreExpected);
            const blocks = data?.pithBlocks || {};
            const blocksActual = blocks.total_blocks_produced ?? null;
            const blocksExpected = blocks.total_blocks_expected ?? blocks.total_blocks ?? null;
            // Show only expected on the card, in full (not compacted to K/L/Cr)
            setText('#totalPithBlocks', blocksExpected, '', false);

            // --- Water Consumed (with unit) ---
            const wm = data?.waterMeter || {};
            let waterText = '—';

            if (wm && wm.normal && wm.normal.total_litres !== undefined && wm.normal.total_litres !== null) {
                const litres = Number(wm.normal.total_litres);
                waterText = (typeof formatToIndianNumberNormal === 'function' ? formatToIndianNumberNormal(litres) : litres) + ' <span class="text-sm font-normal text-gray-500">ltr</span>';
            }
            $('#totalWaterConsumption').html(waterText);
            $('#totalWaterConsumption').attr('title', '');

            // --- Tank Capacity ---
            let totalTankLitres = 0;
            let hasTankData = false;

            if (data.waterTankLevel && typeof data.waterTankLevel === 'object') {
                // Loop through all tanks
                Object.values(data.waterTankLevel).forEach(tank => {
                    // Check if tank data exists and has current level
                    if (tank && typeof tank.current_level_litres !== 'undefined') {
                        totalTankLitres += Number(tank.current_level_litres) || 0;
                        hasTankData = true;
                    }
                });
            }

            if (hasTankData) {
                totalTankLitres = Math.round(totalTankLitres);
                $('#tankCapacity').html((typeof formatToIndianNumberNormal === 'function' ? formatToIndianNumberNormal(totalTankLitres) : totalTankLitres) + ' <span class="text-sm font-normal text-gray-500">ltr</span>');
                $('#tankCapacity').attr('title', '');
            } else {
                $('#tankCapacity').text('—');
                $('#tankCapacity').attr('title', '');
            }

            // --- Labour ---
            const expensesFormatter = (val) => (typeof formatToIndianNumberNormal === 'function' ? formatToIndianNumberNormal(val) : val);
            if (document.getElementById('expense-summary-card')) return;
            if (data && typeof data.expenses_total !== 'undefined') {
                $('#totalExpenses').text('₹' + expensesFormatter(Math.round(Number(data.expenses_total || 0))));
                $('#totalExpenses').attr('title', '');
            } else {
                $('#totalExpenses').text('—');
                $('#totalExpenses').attr('title', '');
            }

        }

        // -- Fetch Dashboard Data based on selected date range --
        function fetchDashboardData(start, end, type = 'day') {
            if (window.loadAlamExpenseWidget) window.loadAlamExpenseWidget(start, end);
            showLoader();
            $.ajax({
                url: '/{{ $teamId }}/dashboard/pith',
                method: 'GET',
                data: {
                    start: start.format('YYYY-MM-DD'),
                    end: end.format('YYYY-MM-DD'),
                    type: type
                },
                success: function (response) {
                    try {
                        const data = response.data;
                        window.isSingleDay = response.isSingleDay;
                        updateSummaryCards(data);

                        if (window.isSingleDay) {
                            renderHourlyMachineStatesChart(data.hourlyMachineState);
                            $('#hourlyMachineStatesWidget').show();
                        } else {
                            $('#hourlyMachineStatesWidget').hide();
                        }

                        // 1. Always render and show the shift progress bars.
                        if (data.pithProduction && data.pithProduction.shifts) {
                            renderPithLoadsShiftChart(data.pithProduction.shifts);
                            $('#pithLoadsShiftWidget').show();
                        } else {
                            $('#pithLoadsShiftWidget').hide();
                        }

                        // 2. Only show the trend chart for date ranges (not a single day).
                        if (!window.isSingleDay && data.pithProduction && data.pithProduction.data && data.pithProduction.data.length > 0) {
                            renderPithLoadsChart(data.pithProduction);
                            $('#pithLoadsWidget').show();
                        } else {
                            $('#pithLoadsWidget').hide();
                        }

                        // Update device states chart with new data
                        renderDeviceStatesChart(data.deviceStateAvg || {});

                        // Update fibre minibales chart with new data
                        if (data.miniBaleProduction) {
                            renderFibreMiniBalesChart(data.miniBaleProduction);
                        } else {
                            // If no fibre production data, show empty state
                            const fibreChart = document.getElementById('fibre-minibales-chart');
                            const fibreEmpty = document.getElementById('fibre-minibales-empty');
                            if (fibreChart) fibreChart.style.display = 'none';
                            if (fibreEmpty) fibreEmpty.classList.remove('hidden');
                        }

                        // Update pith blocks chart with new data
                        @if (auth()->user()->currentTeam && auth()->user()->currentTeam->has_pith_module_blocks)
                            renderPithBlocksChart(data.pithBlocks);
                        @endif

                        // Update water consumption chart with new data
                        @if (auth()->user()->currentTeam && auth()->user()->currentTeam->has_water_meter_module)
                            renderWaterConsumptionChart(data.waterMeter);
                        @endif

                        // Update water tank level chart with new data
                        @if (isset($hasWaterTanks) && $hasWaterTanks)
                            renderTankWaterLevel(data.waterTankLevel, response.end);
                        @endif

                        // Update attendance chart with new data
                        if (data.attendance && data.attendance.length > 0) {
                            renderAttendanceChart(data.attendance);
                            $('#attendanceWidget').show();
                        } else {
                            $('#attendanceWidget').hide();
                        }
                    } catch (error) {
                        console.error('Error processing dashboard data:', error);
                    }
                },
                error: function (xhr) {
                    console.error('Error fetching dashboard data:', xhr);
                    // Show error message to user
                    alert('Failed to load dashboard data. Please try again.');
                },
                complete: function () {
                    hideLoader();
                }
            });
        }

        document.addEventListener('DOMContentLoaded', function() {
            const runSchedulerBtn = document.getElementById('runSchedulerBtn');

            if (runSchedulerBtn) {
                runSchedulerBtn.addEventListener('click', function() {
                    if (confirm("Do you really want to re-run yesterday's cron manually? Note that this will send all notifications and emails again.")) {
                        const teamId = {{ auth()->user()->currentTeam->id }};

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
        function showLoader() {
            $('#loading-overlay').removeClass('hidden');
        }
        function hideLoader() {
            $('#loading-overlay').addClass('hidden');
        }
        window.fetchDashboardData = fetchDashboardData;

    </script>

    <!-- Loading Overlay -->
    <div id="loading-overlay" class="hidden fixed inset-0 bg-white/60 flex items-center justify-center z-50">
        <img src="{{ asset('images/loading-icon.png') }}" class="w-12 h-12 animate-spin">
    </div>

    {{-- Reportrange --}}
</x-app-layout>

