@php
    $canPithInventory = $hasPith && auth()->user()->can('pith_inventory.inventory_report');
    // Husk follows pith (raw material); $hasHusks is set from has_pith_module in ReportsHub.
    $canHuskInventory = $hasHusks && auth()->user()->can('husk_inventory.inventory_report');
    $canBalesInventory = $hasBales && auth()->user()->can('view-bale-inventory');
    $canBlocksInventory = $hasBlocks && auth()->user()->can('view-block-inventory');
    $showInventory = $canPithInventory || $canHuskInventory || $canBalesInventory || $canBlocksInventory;
    $showAttendance = $hasAttendance && auth()->user()->can('attendance.salary_report');
    $showExpenses = app(\App\Services\ExpenseConnection::class)->connected(app('current.team'));
    $hasAnyReports = $showInventory || $showAttendance || $showExpenses;
@endphp

@include('Pulse.livewire.reports.inventory.partials.inventory-report-header', [
    'activeTab' => 'hub',
    'title' => 'Reports'
])

<div class="pt-6 pb-4 ps-2 sm:ps-3 lg:ps-4 pe-3 space-y-5">


    @if (! $hasAnyReports)
        <div class="bg-white rounded-2xl border border-gray-200/80 p-10 shadow-xs text-center space-y-2">
            <div class="mx-auto w-12 h-12 rounded-xl bg-gray-100 flex items-center justify-center text-gray-400">
                <i class="fa-solid fa-lock text-lg"></i>
            </div>
            <h2 class="text-lg font-semibold text-gray-800">{{ __('You don’t have access') }}</h2>
            <p class="text-base text-gray-500 max-w-md mx-auto">
                {{ __('No reports are available for your role or this factory’s enabled modules.') }}
            </p>
        </div>
    @endif

    <!-- INVENTORY Section -->
    @if ($showInventory)
        <div class="space-y-3">
            <h2 class="caps-label text-[#7a221e]">{{ __('INVENTORY') }}</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">

                @if ($canPithInventory)
                    <div class="bg-white rounded-2xl border border-gray-200/80 p-5 shadow-xs flex flex-col justify-between hover:shadow-md transition-shadow">
                        <div>
                            <div class="flex items-center justify-between mb-4">
                                <div class="w-10 h-10 rounded-xl bg-amber-50 flex items-center justify-center text-amber-800">
                                    <x-menu-icon name="pith-inventory" :size="20" />
                                </div>
                                <span class="caps-label">CFT</span>
                            </div>
                            <h3 class="text-lg font-semibold text-gray-800 mb-1">{{ __('Raw Pith Inventory') }}</h3>
                            <p class="text-sm text-gray-500 leading-relaxed">{{ __('Opening, offloaded, taken and closing stock by location group.') }}</p>
                        </div>
                        <div class="flex items-center justify-end mt-6 pt-4 border-t border-gray-100">
                            <a href="{{ route('reports.inventory.pith', ['team' => $teamId, 'start_date' => $startDate, 'end_date' => $endDate, 'preset' => $presetLabel]) }}" class="text-sm font-semibold text-[#7a221e] hover:text-[#5c1916] inline-flex items-center gap-1">
                                <span>Run</span>
                                <i class="fa-solid fa-arrow-right text-xs"></i>
                            </a>
                        </div>
                    </div>
                @endif

                @if ($canHuskInventory)
                    <div class="bg-white rounded-2xl border border-gray-200/80 p-5 shadow-xs flex flex-col justify-between hover:shadow-md transition-shadow">
                        <div>
                            <div class="flex items-center justify-between mb-4">
                                <div class="w-10 h-10 rounded-xl bg-orange-50 flex items-center justify-center text-orange-700">
                                    <x-menu-icon name="husk-inventory" :size="20" />
                                </div>
                                <span class="caps-label">CFT</span>
                            </div>
                            <h3 class="text-lg font-semibold text-gray-800 mb-1">{{ __('Husk Inventory') }}</h3>
                            <p class="text-sm text-gray-500 leading-relaxed">{{ __('Husk received, consumed and remaining, day by day.') }}</p>
                        </div>
                        <div class="flex items-center justify-end mt-6 pt-4 border-t border-gray-100">
                            <a href="{{ route('reports.inventory.husk', ['team' => $teamId, 'start_date' => $startDate, 'end_date' => $endDate, 'preset' => $presetLabel]) }}" class="text-sm font-semibold text-[#7a221e] hover:text-[#5c1916] inline-flex items-center gap-1">
                                <span>Run</span>
                                <i class="fa-solid fa-arrow-right text-xs"></i>
                            </a>
                        </div>
                    </div>
                @endif

                @if ($canBalesInventory)
                    <div class="bg-white rounded-2xl border border-gray-200/80 p-5 shadow-xs flex flex-col justify-between hover:shadow-md transition-shadow">
                        <div>
                            <div class="flex items-center justify-between mb-4">
                                <div class="w-10 h-10 rounded-xl bg-blue-50 flex items-center justify-center text-blue-700">
                                    <x-menu-icon name="fibre-bales" :size="20" />
                                </div>
                                <span class="caps-label">Bales</span>
                            </div>
                            <h3 class="text-lg font-semibold text-gray-800 mb-1">{{ __('Fibre Bales Inventory') }}</h3>
                            <p class="text-sm text-gray-500 leading-relaxed">{{ __('Opening, produced, sold and closing bale count with stock-takes.') }}</p>
                        </div>
                        <div class="flex items-center justify-end mt-6 pt-4 border-t border-gray-100">
                            <a href="{{ route('reports.inventory.bales', ['team' => $teamId, 'start_date' => $startDate, 'end_date' => $endDate, 'preset' => $presetLabel]) }}" class="text-sm font-semibold text-[#7a221e] hover:text-[#5c1916] inline-flex items-center gap-1">
                                <span>Run</span>
                                <i class="fa-solid fa-arrow-right text-xs"></i>
                            </a>
                        </div>
                    </div>
                @endif

                @if ($canBlocksInventory)
                    <div class="bg-white rounded-2xl border border-gray-200/80 p-5 shadow-xs flex flex-col justify-between hover:shadow-md transition-shadow">
                        <div>
                            <div class="flex items-center justify-between mb-4">
                                <div class="w-10 h-10 rounded-xl bg-emerald-50 flex items-center justify-center text-emerald-700">
                                    <x-menu-icon name="pith-blocks" :size="20" />
                                </div>
                                <span class="caps-label">Pith Blocks</span>
                            </div>
                            <h3 class="text-lg font-semibold text-gray-800 mb-1">{{ __('Pith Blocks Inventory') }}</h3>
                            <p class="text-sm text-gray-500 leading-relaxed">{{ __('Block stock movement with corrections over the selected period.') }}</p>
                        </div>
                        <div class="flex items-center justify-end mt-6 pt-4 border-t border-gray-100">
                            <a href="{{ route('reports.inventory.blocks', ['team' => $teamId, 'start_date' => $startDate, 'end_date' => $endDate, 'preset' => $presetLabel]) }}" class="text-sm font-semibold text-[#7a221e] hover:text-[#5c1916] inline-flex items-center gap-1">
                                <span>Run</span>
                                <i class="fa-solid fa-arrow-right text-xs"></i>
                            </a>
                        </div>
                    </div>
                @endif

            </div>
        </div>
    @endif

    @if ($showAttendance)
        <!-- STAFF ATTENDANCE Section -->
        <div class="space-y-3">
            <h2 class="caps-label text-[#7a221e]">{{ __('STAFF ATTENDANCE') }}</h2>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">

                <div class="bg-white rounded-2xl border border-gray-200/80 p-5 shadow-xs flex flex-col justify-between hover:shadow-md transition-shadow">
                    <div>
                        <div class="flex items-center justify-between mb-4">
                            <div class="w-10 h-10 rounded-xl bg-purple-50 flex items-center justify-center text-purple-700">
                                <x-menu-icon name="labour" :size="20" />
                            </div>
                            <span class="caps-label">STAFF</span>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-800 mb-1">{{ __('Staff Attendance & Salary') }}</h3>
                        <p class="text-sm text-gray-500 leading-relaxed">{{ __('Staff days worked, attendance summary and calculated salary payouts.') }}</p>
                    </div>
                    <div class="flex items-center justify-end mt-6 pt-4 border-t border-gray-100">
                        <a href="{{ route('reports.attendance', ['team' => $teamId, 'start_date' => $startDate, 'end_date' => $endDate, 'preset' => $presetLabel]) }}" class="text-sm font-semibold text-[#7a221e] hover:text-[#5c1916] inline-flex items-center gap-1">
                            <span>Run</span>
                            <i class="fa-solid fa-arrow-right text-xs"></i>
                        </a>
                    </div>
                </div>

            </div>
        </div>
    @endif

    @if ($showExpenses)
        <a href="{{ route('reports.expenses', ['team' => $teamId, 'from' => $startDate, 'to' => $endDate]) }}" class="block bg-white rounded-2xl border border-gray-200 p-5 shadow-sm">
            <h2 class="text-lg font-semibold text-[#7a221e]">Expenses</h2>
            <p class="text-sm text-gray-500 mt-1">Alam Cocos expense report</p>
            <span class="inline-block mt-3 text-sm font-semibold text-[#7a221e]">View expense report &rarr;</span>
        </a>
    @endif
</div>
