<x-app-layout>
    <link rel="stylesheet" href="@assetVersion('css/attendance.css')">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@event-calendar/build@4.0.3/dist/event-calendar.min.css">
    <script src="https://cdn.jsdelivr.net/npm/@event-calendar/build@4.0.3/dist/event-calendar.min.js"></script>

    <x-slot name="header">
        <div class="flex items-center gap-4 flex-wrap">
            <div class="relative">
                <div class="absolute inset-0 rounded-xl blur-lg opacity-30" style="background: linear-gradient(135deg, {{ config('colors.pith.total') }}, {{ config('colors.shift.2') }});"></div>
                <div class="relative p-3 rounded-xl shadow-lg" style="background: linear-gradient(135deg, {{ config('colors.pith.total') }}, {{ config('colors.shift.2') }});">
                    <x-menu-icon name="labour" :size="28" class="text-white" />
                </div>
            </div>
            <div>
                <h2 class="text-2xl font-semibold tracking-tight text-gray-900">{{ __('Staff Attendance') }}</h2>
                <p class="text-sm text-gray-500 mt-0.5">Track and manage staff attendance and salary</p>
            </div>
        </div>
    </x-slot>

    @if (session()->has('message'))
        <div class="mx-4 mt-4 p-4 rounded-xl bg-gradient-to-r from-green-50 to-emerald-50 border border-green-200 flex items-center gap-3 shadow-xs animate-slide-in">
            <div class="shrink-0 w-10 h-10 rounded-full bg-green-100 flex items-center justify-center">
                <svg class="w-5 h-5 text-green-600" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                </svg>
            </div>
            <div class="flex-1">
                <p class="text-green-900 font-semibold">{{ session('message') }}</p>
            </div>
            <button onclick="this.parentElement.remove()" class="text-green-600 hover:text-green-800">
                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path>
                </svg>
            </button>
        </div>
    @endif

    <div class="py-3 ps-2 sm:ps-3 lg:ps-4 pe-3">
        <!-- Main Card Container -->
        <div class="bg-white rounded-2xl shadow-xl border border-gray-100 overflow-hidden">
            <!-- Card Top Title Band -->
            <div class="border-b border-gray-100 bg-gradient-to-r from-gray-50 to-white px-3.5 sm:px-4 py-3">
                <h2 class="text-lg font-semibold text-gray-800">Weekly Schedule</h2>
                <p class="text-sm text-gray-500 mt-0.5">Click Add Attendance to manage daily staff entries</p>
            </div>

            <!-- Toolbar Band: Date Navigator (Left) & Summary Metrics Strip (Center) -->
            <div class="border-b border-gray-100 bg-white px-3.5 sm:px-4 py-3 flex items-center justify-between flex-wrap gap-4">
                <!-- Date Controls (Left) -->
                <div class="flex items-center gap-2">
                    <a href="?date={{ $prevWeekDate }}" class="w-8 h-8 flex items-center justify-center rounded-lg border border-gray-200 bg-white hover:bg-gray-50 text-gray-700 shadow-2xs transition-colors" title="Previous Week">
                        <i class="fa-solid fa-chevron-left text-xs"></i>
                    </a>
                    <span id="datePickerTitle" class="h-8 px-3.5 flex items-center justify-center rounded-lg border border-gray-200 bg-white text-sm font-semibold text-gray-800 shadow-2xs cursor-pointer hover:bg-gray-50 hover:text-gray-900 transition-colors">
                        {{ $dateRangeLabel }}
                    </span>
                    <a href="?date={{ $nextWeekDate }}" class="w-8 h-8 flex items-center justify-center rounded-lg border border-gray-200 bg-white hover:bg-gray-50 text-gray-700 shadow-2xs transition-colors" title="Next Week">
                        <i class="fa-solid fa-chevron-right text-xs"></i>
                    </a>
                </div>

                <!-- Center Section: Summary Metrics Strip -->
                <div class="flex-1 flex justify-center min-w-[360px]">
                    <div class="inline-flex items-stretch bg-white border border-gray-200 rounded-xl overflow-hidden shadow-2xs">
                        <!-- Total Staff -->
                        <div class="flex items-center gap-2.5 px-3.5 py-1.5">
                            <div class="w-7 h-7 rounded-lg flex items-center justify-center shrink-0" style="background-color: {{ config('colors.attendance.staff_border') }}1A;">
                                <svg class="w-4 h-4" fill="none" stroke="{{ config('colors.attendance.staff_border') }}" stroke-width="2" viewBox="0 0 24 24">
                                    <path d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                                </svg>
                            </div>
                            <div>
                                <div class="text-xs text-gray-500 font-medium leading-tight">Total Staff</div>
                                <div class="text-sm font-semibold tabular-nums text-gray-900 leading-tight">{{ $overallTotals['totalStaff'] }}</div>
                            </div>
                        </div>
                        <div class="w-px bg-gray-200 shrink-0"></div>

                        <!-- Total Hours -->
                        <div class="flex items-center gap-2.5 px-3.5 py-1.5">
                            <div class="w-7 h-7 rounded-lg flex items-center justify-center shrink-0" style="background-color: {{ config('colors.attendance.hours_border') }}1A;">
                                <svg class="w-4 h-4" fill="none" stroke="{{ config('colors.attendance.hours_border') }}" stroke-width="2" viewBox="0 0 24 24">
                                    <path d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                            </div>
                            <div>
                                <div class="text-xs text-gray-500 font-medium leading-tight">Total Hours</div>
                                <div class="text-sm font-semibold tabular-nums text-gray-900 leading-tight">{{ $overallTotals['totalHours'] }} <span class="text-xs font-normal text-gray-500">hrs</span></div>
                            </div>
                        </div>
                        <div class="w-px bg-gray-200 shrink-0"></div>

                        <!-- Total Salary -->
                        <div class="flex items-center gap-2.5 px-3.5 py-1.5">
                            <div class="w-7 h-7 rounded-lg flex items-center justify-center shrink-0" style="background-color: {{ config('colors.attendance.salary_border') }}1A;">
                                <svg class="w-4 h-4" fill="none" stroke="{{ config('colors.attendance.salary_border') }}" stroke-width="2" viewBox="0 0 24 24">
                                    <path d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                            </div>
                            <div>
                                <div class="text-xs text-gray-500 font-medium leading-tight">Total Salary</div>
                                <div class="text-sm font-semibold tabular-nums text-gray-900 leading-tight">₹{{ \App\Services\NumberFormatterService::formatToIndianFormat($overallTotals['totalSalary'], false) }}</div>
                            </div>
                        </div>
                        <div class="w-px bg-gray-200 shrink-0"></div>

                        <!-- Avg Hours / Staff -->
                        <div class="flex items-center gap-2.5 px-3.5 py-1.5">
                            <div class="w-7 h-7 rounded-lg flex items-center justify-center shrink-0 bg-indigo-50">
                                <svg class="w-4 h-4 text-indigo-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                                </svg>
                            </div>
                            <div>
                                <div class="text-xs text-gray-500 font-medium leading-tight">Avg Hours / Staff</div>
                                <div class="text-sm font-semibold tabular-nums text-indigo-600 leading-tight">{{ $overallTotals['avgHours'] }} <span class="text-xs font-normal text-gray-500">hrs</span></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Native Semantic HTML Table with 100% Guaranteed Column Alignment -->
            <div class="overflow-x-auto">
                <table class="w-full border-collapse text-sm text-left align-middle min-w-[900px]">
                    <!-- Sticky Header Row -->
                    <thead>
                        <tr class="bg-gray-50/90 border-b border-gray-200 caps-label">
                            <th scope="col" class="py-3.5 px-4 sticky left-0 z-20 bg-gray-50 border-r border-gray-200 min-w-[160px] max-w-[160px]">
                                STAFF
                            </th>
                            @foreach ($weekDays as $day)
                                <th scope="col" class="py-3.5 px-4 text-center border-r border-gray-200 min-w-[140px] last:border-r-0">
                                    <span>{{ $day['full_label'] }}</span>
                                </th>
                            @endforeach
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-gray-100">
                        <!-- SUMMARY Row (Positioned directly under column headers) -->
                        <tr class="bg-amber-50/40 border-b border-gray-200 font-medium group">
                            <td class="py-3.5 px-4 sticky left-0 z-10 bg-amber-50/90 border-r border-gray-200 font-semibold text-gray-900 min-w-[160px] max-w-[160px]">
                                SUMMARY
                            </td>
                            @foreach ($weekDays as $day)
                                @php
                                    $dayData = $dailyTotals[$day['date']];
                                    $canManage = Auth::user()->canManageAttendance();
                                @endphp
                                <td class="py-3 px-3 text-center border-r border-gray-200 last:border-r-0">
                                    <div class="flex flex-col items-center justify-center gap-1.5">
                                        @if ($dayData['has_records'])
                                            <div class="text-xs text-gray-500 tabular-nums">
                                                <span class="font-semibold text-gray-900">{{ $dayData['staff_count'] }}</span> staff |
                                                <span class="font-semibold text-gray-900">{{ $dayData['total_hours'] }}h</span> |
                                                <span class="font-semibold text-gray-900">₹{{ \App\Services\NumberFormatterService::formatToIndianFormat($dayData['total_salary'], false) }}</span>
                                            </div>
                                        @endif

                                        @if ($canManage && !$day['is_future'])
                                            <a href="{{ route('attendance.manager', ['team' => $teamId, 'date' => $day['date']]) }}"
                                               class="inline-flex items-center justify-center gap-1 px-3 py-1.5 rounded-md text-xs font-semibold transition-all shadow-2xs {{ $dayData['has_records'] ? 'bg-amber-100/80 text-amber-900 hover:bg-amber-800 hover:text-white' : 'bg-[#7a221e]/10 text-[#7a221e] hover:bg-[#7a221e] hover:text-white' }}">
                                                <i class="fa-solid {{ $dayData['has_records'] ? 'fa-pen-to-square' : 'fa-plus' }} text-xs"></i>
                                                <span>{{ $dayData['has_records'] ? 'Edit Attendance' : 'Add Attendance' }}</span>
                                            </a>
                                        @endif
                                    </div>
                                </td>
                            @endforeach
                        </tr>

                        <!-- Worker Rows -->
                        @foreach ($staffMembers as $staff)
                            <tr class="hover:bg-gray-50/80 transition-colors group">
                                <td class="py-3 px-4 sticky left-0 z-10 bg-white group-hover:bg-gray-50 border-r border-gray-200 text-gray-900 text-sm min-w-[160px] max-w-[160px] truncate"
                                    title="{{ $staff->staff_name }}">
                                    {{ $staff->staff_name }}
                                </td>
                                @foreach ($weekDays as $day)
                                    @php
                                        $att = $attendanceMap[$staff->id][$day['date']] ?? null;
                                    @endphp
                                    <td class="py-3 px-3 text-center border-r border-gray-200 last:border-r-0">
                                        @if ($att && $att->duration > 0)
                                            <div class="inline-flex items-center justify-center gap-1.5 py-1 px-2.5 rounded-lg bg-green-50 border border-green-200 text-green-800 font-semibold text-xs tabular-nums shadow-2xs">
                                                <span>{{ \App\Services\NumberFormatterService::formatToIndianFormat($att->duration, true) }}h</span>
                                                <span class="text-gray-300">|</span>
                                                <span>₹{{ \App\Services\NumberFormatterService::formatToIndianFormat($att->salary, true) }}</span>
                                            </div>
                                        @else
                                            <span class="text-gray-500 text-sm">-</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>

            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof $ !== 'undefined' && $.fn.daterangepicker) {
                $('#datePickerTitle').daterangepicker({
                    singleDatePicker: true,
                    showDropdowns: true,
                    autoApply: true,
                    startDate: moment('{{ $selectedDate }}'),
                    locale: {
                        format: 'YYYY-MM-DD',
                        firstDay: 1,
                    }
                }).on('apply.daterangepicker', function(ev, picker) {
                    window.location.href = '?date=' + picker.startDate.format('YYYY-MM-DD');
                });
            }
        });
    </script>


</x-app-layout>
