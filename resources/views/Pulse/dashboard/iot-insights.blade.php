<!-- IoT Insights Card -->
<div id="iotInsightsWidget" class="bg-white rounded shadow-[0_0_10px_rgba(0,0,0,0.15)] h-full">
    <div class="px-4 pt-4 pb-6">
        <h3 class="text-base font-semibold text-gray-800 mb-2">IoT Insights</h3>
        <hr class="border-t border-gray-400 mb-4">

        <!-- Stats Grid -->
        <div class="grid grid-cols-2 gap-4">
            <!-- Total Staff Card -->
            <div class="bg-white rounded-lg shadow-xs border border-gray-200 p-4">
                <div class="flex items-center gap-3">
                    <div class="p-2 rounded-lg" style="background-color: {{ config('colors.attendance.staff_border') }}1A;">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color: {{ config('colors.attendance.staff_border') }};">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"></path>
                        </svg>
                    </div>
                    <div>
                        <p class="caps-label">Total Staff</p>
                        <p class="text-2xl font-semibold tabular-nums tracking-tight text-gray-800" id="iotTotalStaff">0</p>
                    </div>
                </div>
            </div>

            <!-- Total Hours Card -->
            <div class="bg-white rounded-lg shadow-xs border border-gray-200 p-4">
                <div class="flex items-center gap-3">
                    <div class="p-2 rounded-lg" style="background-color: {{ config('colors.attendance.hours_border') }}1A;">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color: {{ config('colors.attendance.hours_border') }};">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                    <div>
                        <p class="caps-label">Total Hours</p>
                        <p class="text-2xl font-semibold tabular-nums tracking-tight text-gray-800" id="iotTotalHours">0</p>
                    </div>
                </div>
            </div>

            <!-- Total Salary Card -->
            <div class="bg-white rounded-lg shadow-xs border border-gray-200 p-4">
                <div class="flex items-center gap-3">
                    <div class="p-2 rounded-lg" style="background-color: {{ config('colors.attendance.salary_border') }}1A;">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color: {{ config('colors.attendance.salary_border') }};">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                    <div>
                        <p class="caps-label">Total Salary</p>
                        <p class="text-2xl font-semibold tabular-nums tracking-tight text-gray-800" id="iotTotalSalary">₹0</p>
                    </div>
                </div>
            </div>

            <!-- Average Hours Card -->
            <div class="bg-white rounded-lg shadow-xs border border-gray-200 p-4">
                <div class="flex items-center gap-3">
                    <div class="p-2 rounded-lg bg-indigo-50">
                        <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                        </svg>
                    </div>
                    <div>
                        <p class="caps-label">Average Hours</p>
                        <p class="text-2xl font-semibold tabular-nums tracking-tight text-gray-800" id="iotAvgHours">0.0 <span class="text-sm font-normal text-gray-500">hrs</span></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- View Details Link -->
        <div class="mt-4 text-center">
            <a href="{{ route('iot-pith-loads', ['team' => auth()->user()->currentTeam->id]) }}"
               class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-blue-600 hover:text-blue-800 hover:bg-blue-50 rounded-lg transition-colors">
                <span>View Detailed Insights</span>
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                </svg>
            </a>
        </div>
    </div>
</div>

<script>
    function renderIotInsightsCard(data) {
        // Update IoT Insights card with attendance data
        if (data && data.attendance) {
            const totalStaff = data.attendance.length || 0;
            const totalHours = data.attendance.reduce((sum, item) => sum + (item.total_hours || 0), 0);
            const totalSalary = data.attendance.reduce((sum, item) => sum + (item.total_salary || 0), 0);
            const avgHours = totalStaff > 0 ? (totalHours / totalStaff).toFixed(1) : '0.0';

            $('#iotTotalStaff').text(totalStaff);
            $('#iotTotalHours').text(totalHours.toFixed(1));

            // formatToIndianRupees already includes the rupee symbol
            if (typeof formatToIndianRupees === 'function') {
                $('#iotTotalSalary').text(formatToIndianRupees(totalSalary));
            } else {
                $('#iotTotalSalary').text(formatToIndianRupees(totalSalary));
            }

            $('#iotAvgHours').html(avgHours + ' <span class="text-sm font-normal text-gray-500">hrs</span>');

            $('#iotInsightsWidget').show();
        } else {
            $('#iotInsightsWidget').hide();
        }
    }
</script>