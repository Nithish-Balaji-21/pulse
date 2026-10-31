<?php

namespace App\Services\Pulse;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AttendanceExportService
{
    /**
     * Format decimal hours (e.g. 8.53) into human-readable format (e.g. 8h 32m)
     */
    public function formatHours($decimalHours): string
    {
        $duration = (float) $decimalHours;
        if ($duration <= 0) {
            return '0h';
        }

        $hrs = (int) floor($duration);
        $mins = (int) round(($duration - $hrs) * 60);

        if ($mins >= 60) {
            $hrs += 1;
            $mins = 0;
        }

        if ($hrs > 0 && $mins > 0) {
            return "{$hrs}h {$mins}m";
        } elseif ($hrs > 0) {
            return "{$hrs}h";
        } else {
            return "{$mins}m";
        }
    }

    /**
     * Build worker-wise attendance & salary report dataset.
     * Status is Present (duration > 0) or Absent only. Active staff only.
     */
    public function buildReport(int $teamId, Carbon $startDate, Carbon $endDate, array $workerIds = ['all']): array
    {
        $start = $startDate->copy()->startOfDay();
        $end = $endDate->copy()->startOfDay();
        if ($start->gt($end)) {
            [$start, $end] = [$end, $start];
        }
        $startStr = $start->toDateString();
        $endStr = $end->toDateString();

        $emptyPayload = [
            'module' => 'attendance',
            'module_title' => 'Staff Attendance & Salary Report',
            'category' => 'Staff Attendance',
            'unit_label' => '₹',
            'team_id' => $teamId,
            'start_date' => $startStr,
            'end_date' => $endStr,
            'formatted_date_range' => $start->format('d M Y').' – '.$end->format('d M Y'),
            'generated_at' => now(),
            'all_staff_members' => [],
            'applied_filter_label' => $start->format('d M Y').' – '.$end->format('d M Y').' | No workers selected',
            'summary' => [
                'total_workers' => 0,
                'period_days_count' => max(1, $start->diffInDays($end) + 1),
                'total_present_days' => 0,
                'total_absent_days' => 0,
                'attendance_rate' => 0.0,
                'total_hours_decimal' => 0.0,
                'total_hours_formatted' => '0h',
                'total_salary' => 0.0,
                'avg_hours_per_worker' => '0h',
                'overall_avg_hours_per_day_formatted' => '0h',
                'avg_salary_per_worker' => 0.0,
                'avg_daily_labour_cost' => 0.0,
            ],
            'worker_summary' => [],
            'totals' => [
                'total_workers' => 0,
                'total_days_worked' => 0,
                'total_hours_formatted' => '0h',
                'total_salary' => 0.0,
            ],
        ];

        // Empty selection (Clear All) → empty report, never coerce to all
        if ($workerIds === []) {
            return $emptyPayload;
        }

        $filterBySpecificWorkers = ! in_array('all', $workerIds, true);

        $staffQuery = DB::table('staffs')
            ->where('team_id', $teamId)
            ->where('is_active', 1);

        if ($filterBySpecificWorkers) {
            $staffQuery->whereIn('id', $workerIds);
        }

        $staffMembers = $staffQuery
            ->orderBy('staff_name', 'asc')
            ->get(['id', 'staff_name', 'salary_per_hr', 'is_active']);

        if ($staffMembers->isEmpty()) {
            return $emptyPayload;
        }

        $attendanceQuery = DB::table('pulse_attendance')
            ->where('team_id', $teamId)
            ->whereBetween('date', [$startStr, $endStr]);

        if ($filterBySpecificWorkers) {
            $attendanceQuery->whereIn('staff_id', $staffMembers->pluck('id')->all());
        }

        $attendanceEntries = $attendanceQuery->get();

        $attendanceMap = [];
        foreach ($attendanceEntries as $entry) {
            $key = $entry->staff_id.'_'.$entry->date;
            $attendanceMap[$key] = $entry;
        }

        $workerSummaryMap = [];
        foreach ($staffMembers as $staff) {
            $workerSummaryMap[$staff->id] = [
                'staff_id' => $staff->id,
                'staff_name' => $staff->staff_name,
                'salary_per_hr' => (float) ($staff->salary_per_hr ?? 0.0),
                'present_days' => 0,
                'absent_days' => 0,
                'total_duration' => 0.0,
                'total_salary' => 0.0,
            ];
        }

        $period = $start->daysUntil($end);
        $periodDaysCount = max(1, $start->diffInDays($end) + 1);

        foreach ($period as $dateObj) {
            $dateStr = $dateObj->toDateString();

            foreach ($staffMembers as $staff) {
                $key = $staff->id.'_'.$dateStr;
                $entry = $attendanceMap[$key] ?? null;

                $duration = $entry ? (float) ($entry->duration ?? 0.0) : 0.0;
                $salaryPerHr = (float) ($staff->salary_per_hr ?? 0.0);

                if ($entry && isset($entry->salary) && $entry->salary > 0) {
                    $salary = (float) $entry->salary;
                } elseif ($duration > 0 && $salaryPerHr > 0) {
                    // Match AttendanceManager: integer round
                    $salary = (float) round($duration * $salaryPerHr);
                } else {
                    $salary = 0.0;
                }

                $status = $duration > 0 ? 'Present' : 'Absent';

                if ($status === 'Present') {
                    $workerSummaryMap[$staff->id]['present_days']++;
                } else {
                    $workerSummaryMap[$staff->id]['absent_days']++;
                }
                $workerSummaryMap[$staff->id]['total_duration'] += $duration;
                $workerSummaryMap[$staff->id]['total_salary'] += $salary;
            }
        }

        $workerSummaryRows = array_values(array_map(function ($item) {
            $item['total_duration_formatted'] = $this->formatHours($item['total_duration']);
            $avgHoursDecimal = $item['present_days'] > 0 ? ($item['total_duration'] / $item['present_days']) : 0.0;
            $item['avg_hours_per_day_decimal'] = round($avgHoursDecimal, 2);
            $item['avg_hours_per_day_formatted'] = $this->formatHours($avgHoursDecimal);
            $item['total_salary'] = round($item['total_salary'], 2);

            return $item;
        }, $workerSummaryMap));

        $totalWorkers = count($staffMembers);
        $totalPresentDays = array_sum(array_column($workerSummaryRows, 'present_days'));
        $totalAbsentDays = array_sum(array_column($workerSummaryRows, 'absent_days'));
        $totalHoursDecimal = array_sum(array_column($workerSummaryRows, 'total_duration'));
        $totalSalary = round(array_sum(array_column($workerSummaryRows, 'total_salary')), 2);

        $expectedStaffDays = max(1, $totalWorkers * $periodDaysCount);
        $attendanceRate = round(($totalPresentDays / $expectedStaffDays) * 100, 1);

        $avgHoursPerWorker = $totalWorkers > 0 ? $this->formatHours($totalHoursDecimal / $totalWorkers) : '0h';
        $overallAvgHoursPerDay = $totalPresentDays > 0 ? $this->formatHours($totalHoursDecimal / $totalPresentDays) : '0h';
        $avgSalaryPerWorker = $totalWorkers > 0 ? round($totalSalary / $totalWorkers, 2) : 0.0;
        $avgDailyLabourCost = $periodDaysCount > 0 ? round($totalSalary / $periodDaysCount, 2) : 0.0;

        $summary = [
            'total_workers' => $totalWorkers,
            'period_days_count' => $periodDaysCount,
            'total_present_days' => $totalPresentDays,
            'total_absent_days' => $totalAbsentDays,
            'attendance_rate' => $attendanceRate,
            'total_hours_decimal' => $totalHoursDecimal,
            'total_hours_formatted' => $this->formatHours($totalHoursDecimal),
            'total_salary' => $totalSalary,
            'avg_hours_per_worker' => $avgHoursPerWorker,
            'overall_avg_hours_per_day_formatted' => $overallAvgHoursPerDay,
            'avg_salary_per_worker' => $avgSalaryPerWorker,
            'avg_daily_labour_cost' => $avgDailyLabourCost,
        ];

        return [
            'module' => 'attendance',
            'module_title' => 'Staff Attendance & Salary Report',
            'category' => 'Staff Attendance',
            'unit_label' => '₹',
            'team_id' => $teamId,
            'start_date' => $startStr,
            'end_date' => $endStr,
            'formatted_date_range' => $start->format('d M Y').' – '.$end->format('d M Y'),
            'generated_at' => now(),
            'all_staff_members' => $staffMembers->map(fn ($s) => ['id' => $s->id, 'staff_name' => $s->staff_name])->toArray(),
            'applied_filter_label' => $start->format('d M Y').' – '.$end->format('d M Y').' | '.($filterBySpecificWorkers ? count($workerSummaryRows).' Workers' : 'All Workers ('.$totalWorkers.')'),
            'summary' => $summary,
            'worker_summary' => $workerSummaryRows,
            'totals' => [
                'total_workers' => $totalWorkers,
                'total_days_worked' => $totalPresentDays,
                'total_hours_formatted' => $this->formatHours($totalHoursDecimal),
                'total_salary' => $totalSalary,
            ],
        ];
    }
}
