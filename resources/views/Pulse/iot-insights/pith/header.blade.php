    <link rel="stylesheet" href="@assetVersion('css/iotinsights.css')">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@event-calendar/build@4.0.3/dist/event-calendar.min.css">
<script src="https://cdn.jsdelivr.net/npm/@event-calendar/build@4.0.3/dist/event-calendar.min.js"></script>

<x-slot name="header">
    @php
        $team = Auth::user()->currentTeam;
        $currentTeam = $team; // alias for compatibility
        $routes = [];
        if ($team->has_pith_module) {
            $routes['iot-pith-loads'] = 'Pith Production';
        }
        if ($team->has_pith_module_bales) {
            $routes['iot-fibre-minibales'] = 'Fibre Bales';
        }
        if ($team->has_pith_module_blocks) {
            $routes['iot-pith-blocks'] = 'Pith Blocks';
        }
        if ($team->has_water_meter_module) {
            $routes['iot-water-consume'] = 'Water Consumed';
        }
    @endphp

    <div class="flex items-center justify-between flex-wrap gap-4 mb-6">
        <div class="flex items-center gap-4">
            <div class="relative">
                <div class="absolute inset-0 rounded-xl blur-lg opacity-30" style="background: linear-gradient(135deg, {{ config('colors.pith.total') }}, {{ config('colors.shift.2') }});"></div>
                <div class="relative p-3 rounded-xl shadow-lg" style="background: linear-gradient(135deg, {{ config('colors.pith.total') }}, {{ config('colors.shift.2') }});">
                    <x-menu-icon name="iot-insights" :size="28" class="text-white" />
                </div>
            </div>
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-gray-900">Production Insights</h1>
                <p class="text-sm text-gray-500 mt-0.5">Real-time IoT monitoring and analytics</p>
            </div>
        </div>
    </div>

    @if(count($routes) > 1)
        <div class="w-full border-b border-gray-200 mb[-10] px-4">
            <div class="flex items-center justify-center gap-8">
                @foreach ($routes as $route => $label)
                    <a href="{{ route($route, ['team' => $team->id]) }}"
                       class="px-4 py-2.5 font-semibold text-gray-700 border-b-3 transition-all duration-200 hover:text-gray-900 {{ request()->routeIs($route) ? 'border-b-4 text-gray-900' : 'border-transparent hover:border-gray-300' }}"
                       style="{{ request()->routeIs($route) ? 'border-color: ' . config('colors.pith.total') : '' }}">
                        {{ $label }}
                    </a>
                @endforeach
            </div>
        </div>
    @endif
</x-slot>