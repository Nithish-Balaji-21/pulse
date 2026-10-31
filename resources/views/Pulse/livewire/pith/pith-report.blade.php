<div class="w-full py-3 px-2 sm:px-3 lg:px-4">
    <x-slot name="header">
        <div class="flex items-center justify-between flex-wrap gap-3">
            <div class="flex items-center gap-4">
                <div class="relative">
                    <div class="absolute inset-0 rounded-xl blur-lg opacity-30" style="background: linear-gradient(135deg, {{ config('colors.pith.total') }}, {{ config('colors.shift.2') }});"></div>
                    <div class="relative p-3 rounded-xl shadow-lg" style="background: linear-gradient(135deg, {{ config('colors.pith.total') }}, {{ config('colors.shift.2') }});">
                        <i class="fa-solid fa-gauge-high text-white text-xl"></i>
                    </div>
                </div>
                <div>
                    <h2 class="text-2xl font-semibold tracking-tight text-gray-900">Device Utilization Report</h2>
                    <p class="text-sm text-gray-500 mt-0.5">
                        {{ \Carbon\Carbon::parse($pithProduction->shift_date)->format(config('app.date_format')) }}
                        @if ($pithProduction->shift)
                            &middot; {{ $pithProduction->shift->name }}
                        @endif
                    </p>
                </div>
            </div>
            <a href="{{ route('pith.index', ['team' => $teamId]) }}" class="cancel-btn flex items-center gap-1.5 text-sm">
                <i class="fa-solid fa-chevron-left text-xs"></i>
                <span>Back</span>
            </a>
        </div>
    </x-slot>

    <!-- Shift Summary -->
    <div class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-6 gap-3 mt-6">
        <div class="flex rounded-xl border border-gray-200 shadow-md overflow-hidden bg-gradient-to-br from-gray-50 to-gray-100 min-h-[72px]">
            <div class="flex items-center gap-3 px-4 py-3 w-full">
                <div class="w-11 h-11 rounded-lg flex items-center justify-center shadow-md bg-gradient-to-br from-gray-500 to-gray-600 shrink-0">
                    <i class="fa-regular fa-calendar text-white text-base"></i>
                </div>
                <div class="min-w-0 flex-1">
                    <p class="caps-label">Shift Date</p>
                    <p class="text-base font-semibold tabular-nums text-gray-900 leading-tight truncate">
                        {{ \Carbon\Carbon::parse($pithProduction->shift_date)->format(config('app.date_format')) }}
                    </p>
                </div>
            </div>
        </div>

        <div class="flex rounded-xl border border-gray-200 shadow-md overflow-hidden bg-gradient-to-br from-blue-50 to-blue-100 min-h-[72px]">
            <div class="flex items-center gap-3 px-4 py-3 w-full">
                <div class="w-11 h-11 rounded-lg flex items-center justify-center shadow-md bg-gradient-to-br from-blue-500 to-blue-600 shrink-0">
                    <i class="fa-solid fa-layer-group text-white text-base"></i>
                </div>
                <div class="min-w-0 flex-1">
                    <p class="caps-label">Shift Name</p>
                    <p class="text-base font-semibold text-gray-900 leading-tight truncate">
                        {{ $pithProduction->shift ? $pithProduction->shift->name : '-' }}
                    </p>
                </div>
            </div>
        </div>

        <div class="flex rounded-xl border border-gray-200 shadow-md overflow-hidden bg-gradient-to-br from-purple-50 to-purple-100 min-h-[72px]">
            <div class="flex items-center gap-3 px-4 py-3 w-full">
                <div class="w-11 h-11 rounded-lg flex items-center justify-center shadow-md bg-gradient-to-br from-purple-500 to-purple-600 shrink-0">
                    <i class="fa-regular fa-clock text-white text-base"></i>
                </div>
                <div class="min-w-0 flex-1">
                    <p class="caps-label">Time Range</p>
                    <p class="text-base font-semibold tabular-nums text-gray-900 leading-tight truncate">
                        {{ \Carbon\Carbon::parse($pithProduction->start_time)->format('g:i a') }}
                        &ndash; {{ \Carbon\Carbon::parse($pithProduction->end_time)->format('g:i a') }}
                    </p>
                </div>
            </div>
        </div>

        <div class="flex rounded-xl border border-gray-200 shadow-md overflow-hidden bg-gradient-to-br from-indigo-50 to-indigo-100 min-h-[72px]">
            <div class="flex items-center gap-3 px-4 py-3 w-full">
                <div class="w-11 h-11 rounded-lg flex items-center justify-center shadow-md bg-gradient-to-br from-indigo-500 to-indigo-600 shrink-0">
                    <i class="fa-solid fa-user text-white text-base"></i>
                </div>
                <div class="min-w-0 flex-1">
                    <p class="caps-label">Staff</p>
                    <p class="text-base font-semibold text-gray-900 leading-tight truncate">
                        {{ $pithProduction->staff ? $pithProduction->staff->staff_name : '-' }}
                    </p>
                </div>
            </div>
        </div>

        <div class="flex rounded-xl border border-gray-200 shadow-md overflow-hidden bg-gradient-to-br from-red-50 to-red-100 min-h-[72px]"
            title="{{ \App\Services\NumberFormatterService::formatToIndianFormat($pithProduction->loads_produced) }} cft">
            <div class="flex items-center gap-3 px-4 py-3 w-full">
                <div class="w-11 h-11 rounded-lg flex items-center justify-center shadow-md bg-gradient-to-br from-[#861e1a] to-[#a82824] shrink-0">
                    <i class="fa-solid fa-box text-white text-base"></i>
                </div>
                <div class="min-w-0 flex-1">
                    <p class="caps-label">Pith CFT</p>
                    <p class="text-base font-semibold tabular-nums text-[#861e1a] leading-tight truncate">
                        {{ \App\Services\NumberFormatterService::formatCompactNumber($pithProduction->loads_produced) }}
                    </p>
                </div>
            </div>
        </div>

        <div class="flex rounded-xl border border-gray-200 shadow-md overflow-hidden bg-gradient-to-br from-amber-50 to-amber-100 min-h-[72px]"
            title="{{ \App\Services\NumberFormatterService::formatToIndianFormat($ebUnits, true) }} units">
            <div class="flex items-center gap-3 px-4 py-3 w-full">
                <div class="w-11 h-11 rounded-lg flex items-center justify-center shadow-md bg-gradient-to-br from-amber-600 to-amber-700 shrink-0">
                    <i class="fa-solid fa-bolt text-white text-base"></i>
                </div>
                <div class="min-w-0 flex-1">
                    <p class="caps-label">EB Cost</p>
                    <p class="text-base font-semibold tabular-nums text-gray-900 leading-tight truncate">
                        {{ \App\Services\NumberFormatterService::formatToIndianCurrency($ebAmount, 0, true) }}
                    </p>
                </div>
            </div>
        </div>
    </div>

    @if ($pithProduction->comments)
        <div class="mt-3 bg-white rounded-xl border border-gray-200 shadow-xs p-4 flex items-start gap-3">
            <div class="p-2 bg-gray-50 rounded-lg shrink-0">
                <i class="fa-solid fa-comment text-gray-600 text-sm"></i>
            </div>
            <div class="min-w-0">
                <div class="caps-label mb-1">Comments</div>
                <div class="text-sm text-gray-900">{{ $pithProduction->comments }}</div>
            </div>
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mt-4">
        <!-- State Distribution Pie Chart -->
        <div class="bg-white rounded-xl shadow-md border border-gray-200 p-6">
            <div class="flex items-center gap-2 mb-4">
                <div class="p-2 bg-gradient-to-br from-[#861e1a] to-[#a82824] rounded-lg shrink-0">
                    <i class="fa-solid fa-chart-pie text-white text-sm"></i>
                </div>
                <h3 class="text-lg font-semibold text-gray-800">State Distribution</h3>
            </div>
            <div id="chart"></div>
        </div>

        @php
            $states = [
                'efficient' => 'Machine Efficient',
                'idle'      => 'Machine Underloaded',
                'overload'  => 'Machine Overloaded',
                'off'       => 'Machine Off',
                'shutdown'  => 'Machine Shutdown',
                'missing'   => 'Machine Data Missing',
            ];

            // Static, fully-written Tailwind class strings per state — required so the
            // production CSS build (which statically scans this file) can generate them;
            // interpolating a color name at runtime would produce classes Tailwind never sees.
            //
            // These mirror config('colors.states') (which colours the donut chart below):
            // efficient=green, idle=amber, overload=red, off=grey, shutdown=slate,
            // missing=violet. Keep the two in step — they sit on the same screen.
            $stateStyles = [
                'efficient' => ['icon' => 'bolt',                  'iconBg' => 'bg-gradient-to-br from-green-500 to-green-600',  'badge' => 'bg-green-50 text-green-700 border-green-200'],
                'idle'      => ['icon' => 'hourglass-half',        'iconBg' => 'bg-gradient-to-br from-amber-500 to-amber-600',  'badge' => 'bg-amber-50 text-amber-700 border-amber-200'],
                'overload'  => ['icon' => 'triangle-exclamation',  'iconBg' => 'bg-gradient-to-br from-red-500 to-red-600',      'badge' => 'bg-red-50 text-red-700 border-red-200'],
                'off'       => ['icon' => 'power-off',             'iconBg' => 'bg-gradient-to-br from-gray-400 to-gray-500',    'badge' => 'bg-gray-50 text-gray-700 border-gray-200'],
                'shutdown'  => ['icon' => 'plug-circle-xmark',     'iconBg' => 'bg-gradient-to-br from-slate-600 to-slate-700',  'badge' => 'bg-slate-50 text-slate-700 border-slate-200'],
                'missing'   => ['icon' => 'circle-question',       'iconBg' => 'bg-gradient-to-br from-violet-500 to-violet-600','badge' => 'bg-violet-50 text-violet-700 border-violet-200'],
            ];
        @endphp

        @foreach ($states as $stateKey => $stateLabel)
            @php
                $totalDuration = $reportData[$stateKey]['total_duration'] ?? 0;
                $count = $reportData[$stateKey]['state_count'] ?? 0;

                $hours = floor($totalDuration / 3600);
                $minutes = floor(($totalDuration % 3600) / 60);
                $seconds = $totalDuration % 60;
                $formattedDuration = sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
                if ($totalDuration < 1) {
                    continue;
                }
                $style = $stateStyles[$stateKey];
            @endphp

            <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden">
                <div class="p-4 pb-3 flex items-center justify-between gap-3 flex-wrap">
                    <div class="flex items-center gap-3">
                        <div class="w-9 h-9 rounded-lg flex items-center justify-center shadow-md {{ $style['iconBg'] }} shrink-0">
                            <i class="fa-solid fa-{{ $style['icon'] }} text-white text-sm"></i>
                        </div>
                        <h3 class="text-base font-semibold text-gray-800">{{ $stateLabel }}</h3>
                    </div>
                    <span class="text-xs font-semibold px-2.5 py-1 rounded-full border {{ $style['badge'] }} whitespace-nowrap">
                        {{ $formattedDuration }} &middot; {{ $count }} {{ \Illuminate\Support\Str::plural('event', $count) }}
                    </span>
                </div>

                <div class="overflow-x-auto border-t border-gray-200">
                    <table class="w-full">
                        <thead class="bg-gradient-to-r from-gray-50 to-gray-100">
                            <tr class="caps-label border-b border-gray-200">
                                <th class="p-3 font-semibold text-left">Start Time</th>
                                <th class="p-3 font-semibold text-left">End Time</th>
                                <th class="p-3 font-semibold text-left">Duration</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            @forelse ($reportData['top_' . $stateKey] as $state)
                                @php
                                    $formattedStartTime = \Carbon\Carbon::parse($state->adjusted_start_time)->format('g:i:s a');
                                    $formattedEndTime = \Carbon\Carbon::parse($state->adjusted_end_time)->format('g:i:s a');

                                    $rowHours = floor($state->adjusted_duration / 3600);
                                    $rowMinutes = floor(($state->adjusted_duration % 3600) / 60);
                                    $rowSeconds = $state->adjusted_duration % 60;
                                    $rowFormattedDuration = sprintf('%02d:%02d:%02d', $rowHours, $rowMinutes, $rowSeconds);
                                @endphp
                                <tr class="hover:bg-gray-50 transition-colors">
                                    <td class="p-3 text-sm tabular-nums text-gray-900">{{ $formattedStartTime }}</td>
                                    <td class="p-3 text-sm tabular-nums text-gray-900">{{ $formattedEndTime }}</td>
                                    <td class="p-3 text-sm tabular-nums text-gray-900">{{ $rowFormattedDuration }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="p-6 text-center">
                                        <div class="flex flex-col items-center gap-2">
                                            <i class="fa-solid fa-inbox text-gray-300 text-2xl"></i>
                                            <p class="text-sm text-gray-500">No occurrences recorded</p>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="border-t border-gray-200 bg-gray-50">
                    {{ $reportData['top_' . $stateKey]->links() }}
                </div>
            </div>
        @endforeach
    </div>
</div>
@push('scripts')
<script>
    const chartData = {!! json_encode($chartData) !!};

    // Get the state labels and their respective counts
    const labels = Object.keys(chartData);
    const series = Object.values(chartData).map(item => Number(item.total_duration));

    const stateColors = @json(config('colors.states'));
    const colors = labels.map(label => stateColors[label] || '#cccccc'); // fallback color if label not in stateColors

    // Function to convert seconds
    function formatDuration(seconds) {
        let minutes = Math.floor(seconds / 60);

        // Apply seconds rules directly to minutes
        if (seconds % 60 >= 56) {
            minutes += 1;
        }

        // Calculate hours and adjust minutes according to the rules
        let hours = Math.floor(minutes / 60);
        minutes = minutes % 60;

        // Apply minutes rules
        if (minutes >= 58) {
            hours += 1;
            minutes = 0;
        } else if (minutes < 2) {
            minutes = 0;
        }

        // Format the output based on hours and minutes, omitting minutes if they are 0
        if (hours > 0 && minutes > 0) {
            return `${hours}h ${minutes}m`;
        } else if (hours > 0) {
            return `${hours}h`;
        } else {
            return `${minutes}m`;
        }
    }

    var options = {
        chart: {
            type: 'pie',
            width: 500,
        },
        labels: labels,
        series: series,
        colors: colors,
        title: {
            text: '',
            align: 'center'
        },
        dataLabels: {
            enabled: true,
            dropShadow: { enabled: true, top: 1, left: 1, blur: 2, opacity: 0.5 },

            formatter: function (val, opts) {
                const durationInSeconds = opts.w.config.series[opts.seriesIndex];
                const formattedDuration = formatDuration(durationInSeconds);
                return `${formattedDuration}`; // Display % and HH:mm format
            },
            style: {
                fontSize: '14px',
            }
        },
        legend: {
            position: 'left',
            verticalAlign: 'middle',
            fontSize: '14px'
        },
        tooltip: {
            y: {
                formatter: (value) => {
                    const formattedDuration = formatDuration(value);
                    return `${formattedDuration}`;
                }
            }
        }
    };

    var chart = new ApexCharts(document.querySelector("#chart"), options);
    chart.render();
</script>
@endpush
