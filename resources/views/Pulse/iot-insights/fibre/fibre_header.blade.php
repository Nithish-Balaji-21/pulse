<link rel="stylesheet" href="@assetVersion('css/iotinsights.css')">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@event-calendar/build@4.0.3/dist/event-calendar.min.css">
<script src="https://cdn.jsdelivr.net/npm/@event-calendar/build@4.0.3/dist/event-calendar.min.js"></script>

@php
    $team = Auth::user()->currentTeam;
    $routes = [];

    if ($team->has_fibre_module) {
        $routes['iot-fibre-bales'] = 'Bales Produced';
    }
    if ($team->has_fibre_module_weight) {
        $routes['iot-fibre-weight'] = 'Bales Weighed';
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
            <h1 class="text-2xl font-semibold tracking-tight text-gray-900">Fibre Production Insights</h1>
            <p class="text-sm text-gray-500 mt-0.5">Real-time IoT monitoring and analytics</p>
        </div>
    </div>
</div>

@if(count($routes) > 1)
    <div class="w-full border-b border-gray-200 mb-6 px-4">
        <div class="flex items-center justify-center gap-8">
            @foreach ($routes as $route => $label)
                <a href="/{{ $currentTeam->id }}/{{ $route }}"
                   class="px-4 py-2.5 font-semibold text-gray-700 border-b-3 transition-all duration-200 hover:text-gray-900
                          {{ request()->is($currentTeam->id.'/'.$route) ? 'border-b-4 text-gray-900' : 'border-transparent hover:border-gray-300' }}"
                   style="{{ request()->is($currentTeam->id.'/'.$route) ? 'border-color: ' . config('colors.fibre.total') : '' }}">
                    {{ $label }}
                </a>
            @endforeach
        </div>
    </div>
@endif
