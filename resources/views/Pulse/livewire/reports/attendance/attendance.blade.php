@include('Pulse.livewire.reports.inventory.partials.inventory-report-header', [
    'title' => 'Staff Attendance Report',
    'activeTab' => 'attendance',
])

<div class="pt-6 pb-4 ps-2 sm:ps-3 lg:ps-4 pe-3 space-y-5">
    <div class="pb-3 border-b border-gray-200/70">
        {{-- Title + Staffs filter + Export PDF --}}
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-gray-900">Staff Attendance Report</h1>
                <p class="text-sm text-gray-500 mt-0.5">
                    Staff attendance, working hours and salary analysis
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-3 sm:justify-end">
                <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                    <button type="button" @click="open = !open" class="px-4 py-2 rounded-xl border border-gray-300 text-sm font-semibold text-gray-800 bg-white hover:bg-gray-50 flex items-center gap-2 shadow-xs min-w-[170px] justify-between">
                        <div class="flex items-center gap-2 truncate">
                            <i class="fa-solid fa-users text-gray-400"></i>
                            <span class="truncate">
                                @if (count($selectedWorkers) === 0)
                                    Staffs: 0 Selected
                                @elseif (in_array('all', $selectedWorkers, true) || count($selectedWorkers) === $totalActiveStaffCount)
                                    Staffs: All Staffs
                                @elseif (count($selectedWorkers) === 1)
                                    Staffs: 1 Selected
                                @else
                                    Staffs: {{ count($selectedWorkers) }} Selected
                                @endif
                            </span>
                        </div>
                        <i class="fa-solid fa-chevron-down text-xs text-gray-400 transition-transform" :class="{ 'rotate-180': open }"></i>
                    </button>

                    <div x-show="open" x-cloak class="absolute right-0 mt-2 w-64 bg-white rounded-2xl shadow-xl border border-gray-200 z-50 p-3 space-y-2">
                        <div class="relative">
                            <i class="fa-solid fa-magnifying-glass absolute left-3 top-2.5 text-sm text-gray-400"></i>
                            <input type="text" wire:model.live="workerSearch" placeholder="Search staff..." class="w-full pl-8 pr-3 py-1.5 text-sm rounded-xl border border-gray-200 focus:outline-hidden focus:border-[#7a221e]">
                        </div>

                        <div class="flex items-center justify-between text-xs font-semibold px-1 pt-1 border-b border-gray-100 pb-1.5">
                            <button type="button" wire:click="selectAllWorkers" class="text-[#7a221e] hover:underline">Select All</button>
                            <button type="button" wire:click="unselectAllWorkers" class="text-gray-500 hover:underline">Clear All</button>
                        </div>

                        <div class="max-h-48 overflow-y-auto space-y-1 custom-scroll pr-1">
                            @forelse ($staffList as $staff)
                                @php
                                    $isSelected = in_array('all', $selectedWorkers, true) || in_array($staff->id, $selectedWorkers, true);
                                @endphp
                                <label class="flex items-center gap-2.5 px-2 py-1.5 rounded-lg hover:bg-gray-50 cursor-pointer text-sm">
                                    <input type="checkbox" wire:click="toggleWorker({{ $staff->id }})" {{ $isSelected ? 'checked' : '' }} class="rounded-md border-gray-300 text-[#7a221e] focus:ring-[#7a221e]">
                                    <span class="text-gray-900 truncate">{{ $staff->staff_name }}</span>
                                </label>
                            @empty
                                <div class="text-sm text-gray-500 text-center py-2">No staff found</div>
                            @endforelse
                        </div>
                    </div>
                </div>

                <button type="button" wire:click="exportPdf" class="px-4 py-2 rounded-xl border border-gray-300 text-sm font-semibold text-gray-700 bg-white hover:bg-gray-50 shadow-xs flex items-center gap-2">
                    <i class="fa-solid fa-file-pdf text-red-600"></i>
                    <span>Export PDF</span>
                </button>
            </div>
        </div>
    </div>


    <!-- Staff-wise Summary Table -->
    <div class="bg-white rounded-2xl border border-gray-200/80 shadow-xs overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <h2 class="text-lg font-semibold text-gray-800">Staff-wise Summary</h2>
            <span class="text-sm text-gray-500 tabular-nums">{{ count($reportData['worker_summary'] ?? []) }} Staffs</span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-sm">
                <thead>
                    <tr class="border-b border-gray-100 bg-gray-50/70 caps-label">
                        <th class="py-3 px-6">STAFF NAME</th>
                        <th class="py-3 px-6 text-right">PRESENT DAYS</th>
                        <th class="py-3 px-6 text-right">ABSENT DAYS</th>
                        <th class="py-3 px-6 text-right">AVG. HOURS / DAY</th>
                        <th class="py-3 px-6 text-right">TOTAL HOURS WORKED</th>
                        <th class="py-3 px-6 text-right">TOTAL SALARY (₹)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 tabular-nums">
                    @forelse ($reportData['worker_summary'] ?? [] as $workerRow)
                        <tr class="hover:bg-gray-50/50">
                            <td class="py-3 px-6 text-gray-900">{{ $workerRow['staff_name'] }}</td>
                            <td class="py-3 px-6 text-right text-gray-900">{{ $workerRow['present_days'] }}</td>
                            <td class="py-3 px-6 text-right {{ $workerRow['absent_days'] > 0 ? 'text-red-600' : 'text-gray-900' }}">{{ $workerRow['absent_days'] }}</td>
                            <td class="py-3 px-6 text-right text-gray-900">{{ $workerRow['avg_hours_per_day_formatted'] }}</td>
                            <td class="py-3 px-6 text-right text-gray-900">{{ $workerRow['total_duration_formatted'] }}</td>
                            <td class="py-3 px-6 text-right text-gray-900">₹{{ number_format($workerRow['total_salary'], 2) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-12 text-center text-gray-500">
                                @if (count($selectedWorkers) === 0)
                                    Select staff to run the attendance report.
                                @else
                                    No staff summary data available for the selected range.
                                @endif
                            </td>
                        </tr>
                    @endforelse

                    @if (!empty($reportData['worker_summary']))
                        <tr class="font-semibold text-gray-900 bg-gray-50 border-t-2 border-gray-200">
                            <td class="py-3.5 px-6">Total ({{ count($reportData['worker_summary']) }} Staffs)</td>
                            <td class="py-3.5 px-6 text-right">{{ $reportData['summary']['total_present_days'] ?? 0 }}</td>
                            <td class="py-3.5 px-6 text-right {{ ($reportData['summary']['total_absent_days'] ?? 0) > 0 ? 'text-red-600' : '' }}">{{ $reportData['summary']['total_absent_days'] ?? 0 }}</td>
                            <td class="py-3.5 px-6 text-right">{{ $reportData['summary']['overall_avg_hours_per_day_formatted'] ?? '0h' }}</td>
                            <td class="py-3.5 px-6 text-right">{{ $reportData['summary']['total_hours_formatted'] ?? '0h' }}</td>
                            <td class="py-3.5 px-6 text-right">₹{{ number_format($reportData['summary']['total_salary'] ?? 0, 2) }}</td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>
    </div>
</div>
