@php
    $title = $title ?? 'Reports';
    $activeTab = $activeTab ?? 'hub';

    $currentTeam = auth()->user()?->activeTeam() ?? auth()->user()?->currentTeam;
    $teamId = $teamId ?? $currentTeam?->id;
    $startDate = $startDate ?? session("global_report_start_date_{$teamId}", now()->subDay()->toDateString());
    $endDate = $endDate ?? session("global_report_end_date_{$teamId}", now()->subDay()->toDateString());
    $presetLabel = $presetLabel ?? session("global_report_preset_{$teamId}", 'Yesterday');

    $dateQuery = function ($tabRoute) use ($teamId, $startDate, $endDate, $presetLabel) {
        return route($tabRoute, [
            'team' => $teamId,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'preset' => $presetLabel,
        ]);
    };

    $canPith = $currentTeam?->has_pith_module && auth()->user()->can('pith_inventory.inventory_report');
    $canHusk = $currentTeam?->has_pith_module && auth()->user()->can('husk_inventory.inventory_report');
    $canBales = $currentTeam?->has_pith_module_bales && auth()->user()->can('view-bale-inventory');
    $canBlocks = $currentTeam?->has_pith_module_blocks && auth()->user()->can('view-block-inventory');
    $canAttendance = $currentTeam?->has_attendance_module && auth()->user()->can('attendance.salary_report');

    $routes = [];
    if ($canPith) {
        $routes['reports.inventory.pith'] = ['label' => 'Raw Pith', 'key' => 'pith'];
    }
    if ($canHusk) {
        $routes['reports.inventory.husk'] = ['label' => 'Husk', 'key' => 'husk'];
    }
    if ($canBales) {
        $routes['reports.inventory.bales'] = ['label' => 'Fibre Bales', 'key' => 'bales'];
    }
    if ($canBlocks) {
        $routes['reports.inventory.blocks'] = ['label' => 'Pith Blocks', 'key' => 'blocks'];
    }
    if ($canAttendance) {
        $routes['reports.attendance'] = ['label' => 'Staff Attendance', 'key' => 'attendance'];
    }
    if ($currentTeam && app(\App\Services\ExpenseConnection::class)->connected($currentTeam)) {
        $routes['reports.expenses'] = ['label' => 'Expenses', 'key' => 'expenses'];
    }
@endphp

<x-slot name="header">
    <div class="flex items-center justify-between flex-wrap gap-4 pb-1">
        <div class="flex items-center gap-4">
            <div class="relative">
                <div class="absolute inset-0 rounded-xl blur-lg opacity-30" style="background: linear-gradient(135deg, {{ config('colors.pith.total', '#7a221e') }}, {{ config('colors.shift.2', '#d97706') }});"></div>
                <div class="relative p-3 rounded-xl shadow-lg" style="background: linear-gradient(135deg, {{ config('colors.pith.total', '#7a221e') }}, {{ config('colors.shift.2', '#d97706') }});">
                    <x-menu-icon name="reports" :size="28" class="text-white" />
                </div>
            </div>
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-gray-900">Reports</h1>
                <p class="text-sm text-gray-500 mt-0.5">Comprehensive reports and inventory analytics</p>
            </div>
        </div>

        <div>
            @livewire('pulse.reports.shared.date-range-picker', [
                'startDate' => $startDate,
                'endDate' => $endDate,
                'presetLabel' => $presetLabel,
            ], key('reports-shared-date-range'))
        </div>
    </div>

    @if (count($routes) > 0 && ($activeTab ?? '') !== 'hub')
        <div class="w-full border-b border-gray-200 mt-6 pt-1">
            <div class="flex flex-wrap items-center justify-center gap-x-8 gap-y-2">
                @foreach ($routes as $route => $item)
                    <a href="{{ $dateQuery($route) }}"
                       class="px-3 py-2.5 text-base font-semibold text-gray-700 transition-all duration-200 hover:text-gray-900 {{ ($activeTab ?? '') === $item['key'] || request()->routeIs($route) ? 'border-b-4 text-gray-900' : 'border-b-3 border-transparent hover:border-gray-300' }}"
                       style="{{ ($activeTab ?? '') === $item['key'] || request()->routeIs($route) ? 'border-color: ' . config('colors.pith.total', '#7a221e') : '' }}">
                        {{ __($item['label']) }}
                    </a>
                @endforeach
            </div>
        </div>
    @endif
</x-slot>


