<link rel="stylesheet" href="@assetVersion('css/iotinsights.css')">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@event-calendar/build@4.0.3/dist/event-calendar.min.css">
<script src="https://cdn.jsdelivr.net/npm/@event-calendar/build@4.0.3/dist/event-calendar.min.js"></script>

<div class="flex items-center justify-between flex-wrap gap-4 mb-6">
    <div class="flex items-center gap-4">
        <div class="relative">
            <div class="absolute inset-0 rounded-xl blur-lg opacity-30" style="background: linear-gradient(135deg, {{ config('colors.shift.1') }}, {{ config('colors.fibre.total') }});"></div>
            <div class="relative p-3 rounded-xl shadow-lg" style="background: linear-gradient(135deg, {{ config('colors.shift.1') }}, {{ config('colors.fibre.total') }});">
                <x-menu-icon name="iot-insights" :size="28" class="text-white" />
            </div>
        </div>
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-gray-900">Growbag Production Insights</h1>
            <p class="text-sm text-gray-500 mt-0.5">Real-time IoT monitoring and analytics</p>
        </div>
    </div>
</div>

@if(isset($machines) && $machines->count() > 1)
<div class="w-full border-b border-gray-200 mb-6 px-4">
    <div class="flex items-center justify-center gap-8">
        @foreach($machines as $machine)
            <a href="/{{ $teamId }}/iot-growbags?machine={{ $machine->id }}"
               class="px-4 py-2.5 font-semibold text-gray-700 border-b-3 transition-all duration-200 hover:text-gray-900
                      {{ $selectedMachineId == $machine->id ? 'border-b-4 text-gray-900' : 'border-transparent hover:border-gray-300' }}"
               style="{{ $selectedMachineId == $machine->id ? 'border-color: ' . config('colors.shift.1') : '' }}">
                {{ $machine->machine_name }}
            </a>
        @endforeach
    </div>
</div>
@endif