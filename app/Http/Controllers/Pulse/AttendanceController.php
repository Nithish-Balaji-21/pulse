<?php

namespace App\Http\Controllers\Pulse;

use App\Http\Controllers\Controller;
use App\Models\Pulse\Attendance;
use App\Models\Staff;
use App\Services\NumberFormatterService;
use App\Services\Pulse\AttendanceExportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class AttendanceController extends Controller
{
    public function index()
    {
        $teamId = Auth::user()->currentTeam->id;
        $today = now()->toDateString();
        $selectedDate = request('date') ?? $today;

        // Determine 7-day weekly window (Monday -> Sunday)
        $currentCarbon = Carbon::parse($selectedDate);
        $startOfWeek = $currentCarbon->copy()->startOfWeek(Carbon::MONDAY);
        $endOfWeek = $currentCarbon->copy()->endOfWeek(Carbon::SUNDAY);

        $weekDays = [];
        $period = Carbon::parse($startOfWeek)->daysUntil($endOfWeek);
        foreach ($period as $day) {
            $dateStr = $day->format('Y-m-d');
            $weekDays[] = [
                'date' => $dateStr,
                'day_name' => strtoupper($day->format('D')),
                'date_num' => $day->format('d'),
                'month_name' => strtoupper($day->format('M')),
                'full_label' => strtoupper($day->format('D, d M')),
                'is_today' => ($dateStr === $today),
                'is_future' => $day->greaterThan(now()->endOfDay()),
            ];
        }

        // Fetch active staff in the specified team
        $staffMembers = DB::table('staffs')
            ->where('team_id', $teamId)
            ->where('is_active', 1)
            ->orderBy('staff_name', 'asc')
            ->get();

        // Fetch attendance records within the week window
        $records = DB::table('pulse_attendance')
            ->where('team_id', $teamId)
            ->whereBetween('date', [$startOfWeek->format('Y-m-d'), $endOfWeek->format('Y-m-d')])
            ->get();

        // Build attendance map indexed by [staff_id][date]
        $attendanceMap = [];
        $dailyTotals = [];
        foreach ($weekDays as $dayInfo) {
            $dailyTotals[$dayInfo['date']] = [
                'staff_count' => 0,
                'total_hours' => 0,
                'total_salary' => 0,
                'has_records' => false,
            ];
        }

        foreach ($records as $record) {
            $attendanceMap[$record->staff_id][$record->date] = $record;

            if (isset($dailyTotals[$record->date])) {
                $dailyTotals[$record->date]['staff_count']++;
                $dailyTotals[$record->date]['total_hours'] += (float) $record->duration;
                $dailyTotals[$record->date]['total_salary'] += (float) $record->salary;
                $dailyTotals[$record->date]['has_records'] = true;
            }
        }

        // Calculate overall totals for the weekly view
        $uniqueStaffWorked = $records->unique('staff_id')->count();
        $totalHours = $records->sum('duration');
        $totalSalary = $records->sum('salary');
        $avgHours = $uniqueStaffWorked > 0 ? round($totalHours / $uniqueStaffWorked, 1) : 0;

        $overallTotals = [
            'totalStaff' => $uniqueStaffWorked,
            'totalHours' => round($totalHours, 1),
            'totalSalary' => round($totalSalary),
            'avgHours' => number_format($avgHours, 1),
        ];

        // Legacy format for backward compatibility if needed
        $staffs = collect([
            ['id' => 'total', 'title' => 'TOTAL'],
        ])->merge($staffMembers->map(function ($staff) {
            return [
                'id' => $staff->id,
                'title' => $staff->staff_name,
            ];
        }));

        $prevWeekDate = $startOfWeek->copy()->subWeek()->format('Y-m-d');
        $nextWeekDate = $startOfWeek->copy()->addWeek()->format('Y-m-d');
        if ($startOfWeek->month === $endOfWeek->month) {
            $dateRangeLabel = $startOfWeek->format('j').'–'.$endOfWeek->format('j M Y');
        } else {
            $dateRangeLabel = $startOfWeek->format('j M').' – '.$endOfWeek->format('j M Y');
        }

        return view('pulse::attendance', array_merge(
            compact(
                'staffMembers',
                'staffs',
                'weekDays',
                'attendanceMap',
                'dailyTotals',
                'overallTotals',
                'selectedDate',
                'today',
                'prevWeekDate',
                'nextWeekDate',
                'dateRangeLabel'
            ),
            ['teamId' => $teamId, 'date' => $selectedDate]
        ));
    }

    public function getStaffAttendanceSummary(Request $request)
    {
        // Get the start and end dates from the request
        $startDate = Carbon::parse($request->query('start'))->format('Y-m-d');
        $endDate = Carbon::parse($request->query('end'))->format('Y-m-d');
        $teamId = Auth::user()->currentTeam->id;

        // Fetch attendance data within the date range and filter by team_id
        $attendanceRecords = DB::table('pulse_attendance')
            ->where('team_id', $teamId)
            ->whereBetween('date', [$startDate, $endDate])
            ->get();

        // Calculate overall totals
        $totalResources = $attendanceRecords->groupBy('staff_id')->count();
        $totalHours = $attendanceRecords->sum('duration');
        $totalSalary = $attendanceRecords->sum('salary');

        // Group the records by date and calculate daily totals
        $dailyTotals = $attendanceRecords->groupBy('date')->map(function ($group) {
            return [
                'staff' => $group->count(),
                'duration' => $group->sum('duration'),
                'salary' => $group->sum('salary'),
            ];
        });

        $dateSummary = collect();

        // Loop over the dates and add a "Total" event and the link to add/edit attendance
        $period = Carbon::parse($startDate)->daysUntil($endDate);
        foreach ($period as $date) {
            if ($date->greaterThan(Carbon::today())) {
                continue;
            }

            $dateStr = $date->format('Y-m-d');

            // Get daily totals, default to zero if no records exist
            $totals = $dailyTotals->get($dateStr, ['staff' => 0, 'duration' => 0, 'salary' => 0]);

            // Add a "Total" event for this date
            $dateSummary->push([
                'id' => "$dateStr",
                'staffs' => $totals['staff'],
                'durations' => $totals['duration'],
                'salary' => $totals['salary'],
            ]);
        }

        // Structure response to include events and totals
        return response()->json([
            'days' => $dateSummary,
            'totals' => [
                'totalResources' => $totalResources,
                'totalHours' => $totalHours,
                'totalSalary' => round($totalSalary),
            ],
        ]);
    }

    // Fetch attendance records based on the date range
    public function getStaffAttendanceDetails(Request $request)
    {
        // Get the start and end dates from the request
        $startDate = Carbon::parse($request->query('start'))->format('Y-m-d');
        $endDate = Carbon::parse($request->query('end'))->format('Y-m-d');
        $teamId = Auth::user()->currentTeam->id;

        // Fetch attendance data within the date range and filter by team_id
        $attendanceRecords = DB::table('pulse_attendance')
            ->join('staffs', 'pulse_attendance.staff_id', '=', 'staffs.id')
            ->where('pulse_attendance.team_id', $teamId)
            ->whereBetween('pulse_attendance.date', [$startDate, $endDate])
            ->select('pulse_attendance.*', 'staffs.staff_name as staff_name')
            ->get();

        // Calculate overall totals - count unique staff who actually worked
        $totalResources = $attendanceRecords->unique('staff_id')->count();
        $totalHours = $attendanceRecords->sum('duration');
        $totalSalary = $attendanceRecords->sum('salary');

        // Group attendance records by date and calculate daily totals
        $dailyTotals = $attendanceRecords->groupBy('date')->map(function ($group) {
            return [
                'staff' => $group->unique('staff_id')->count(),
                'duration' => $group->sum('duration'),
                'salary' => $group->sum('salary'),
            ];
        });

        // Format the attendance records to match the expected structure
        $events = $attendanceRecords->map(function ($attendance) {
            // Create a rich, formatted title with better structure
            $hours = NumberFormatterService::formatToIndianFormat($attendance->duration, true);
            $salary = NumberFormatterService::formatToIndianFormat($attendance->salary, true);

            // Build the title with better formatting
            $title = "⏱️ {$hours}h | 💰 ₹{$salary}";

            if (! empty($attendance->task_description)) {
                // Truncate long descriptions for display
                $task = strlen($attendance->task_description) > 50
                    ? substr($attendance->task_description, 0, 47).'...'
                    : $attendance->task_description;
                $title .= "\n📋 ".$task;
            }

            return [
                'id' => $attendance->id,
                'resourceId' => $attendance->staff_id,
                'start' => $attendance->date,
                'end' => $attendance->date,
                'title' => $title,
                'classNames' => 'ec-staff',
                'extendedProps' => [
                    'staffName' => $attendance->staff_name,
                    'durationHrs' => $attendance->duration,
                    'salary' => $attendance->salary,
                    'taskDescription' => $attendance->task_description,
                ],
            ];
        });

        // The per-day "Add / Edit Attendance" cell is the only way into the editor,
        // so it is the module's add/edit button: readers and staff sit below
        // manage-attendance and never see it. The editor route enforces the same
        // gate, and this keeps the calendar from offering a link that 403s.
        $canManageAttendance = Gate::allows('manage-attendance');

        // Loop over the dates and add a "Total" event and the link to add/edit attendance
        $period = Carbon::parse($startDate)->daysUntil($endDate);
        foreach ($period as $date) {
            if ($date->greaterThan(Carbon::today())) {
                continue;
            }

            $dateStr = $date->format('Y-m-d');
            $totals = $dailyTotals->get($dateStr, ['staff' => 0, 'duration' => 0, 'salary' => 0]);

            // Determine if there are records for the day and prepare the corresponding link
            $linkTitle = isset($dailyTotals[$dateStr]) ? '✏️ Edit' : '+ Add Attendance';

            // Add an event for "add attendance" or "edit attendance" as a separate event
            if ($canManageAttendance) {
                $events->push([
                    'id' => "$dateStr",
                    'resourceId' => 'total',
                    'start' => $dateStr,
                    'end' => $dateStr,
                    'title' => $linkTitle,
                    'classNames' => ['edit-link'],
                    'extendedProps' => [
                        'editLink' => true,
                    ],
                ]);
            }

            if ($totals['staff'] > 0) {
                // Add a "Total" event for this date with better formatting
                $staffText = $totals['staff'].' '.($totals['staff'] == 1 ? 'person' : 'people');
                $hoursText = NumberFormatterService::formatToIndianFormat($totals['duration'], true).'h';
                $salaryText = NumberFormatterService::formatToIndianCurrency($totals['salary'], 0, true);

                $text = "👥 {$staffText} | ⏱️ {$hoursText} | 💰 {$salaryText}";

                $events->push([
                    'id' => "total_$dateStr",
                    'resourceId' => 'total',
                    'start' => $dateStr,
                    'end' => $dateStr,
                    'title' => $text,
                    'classNames' => 'ec-all-day-total',
                    'extendedProps' => $totals,
                ]);
            }
        }

        // Structure response to include events and totals
        return response()->json([
            'events' => $events,
            'totals' => [
                'totalResources' => $totalResources,
                'totalHours' => $totalHours,
                'totalSalary' => round($totalSalary),
            ],
        ]);
    }

    public function add()
    {
        if (! Gate::allows('attendance.create')) {
            return response()->json(['error' => 'You are not authorized to add pulse_attendance.'], 403);
        }

        $currentTeamId = Auth::user()->currentTeam->id;

        $staff = Staff::select('id', 'staff_name', 'salary_per_hr')
            ->where('team_id', $currentTeamId)
            ->where('is_active', 1)
            ->orderBy('staff_name', 'asc')
            ->get();

        return response()->json(['staffs' => $staff], 200);
    }

    public function destroy($id)
    {
        if (! Gate::allows('attendance.edit')) {
            return response()->json(['error' => 'You are not authorized for this action - delete pulse_attendance.'], 403);
        }

        $currentTeamId = Auth::user()->currentTeam->id;

        // Attempt deletion
        $deleted = Attendance::where('id', $id)
            ->where('team_id', $currentTeamId)
            ->delete();

        if ($deleted === 0) {
            return response()->json(['error' => 'Attendance ID does not exist or not authorized.'], 400);
        }

        return response()->json(['message' => 'Attendance deleted successfully.'], 200);
    }

    public function edit($id)
    {
        if (! Gate::allows('attendance.edit')) {
            return response()->json(['error' => 'You are not authorized to edit pulse_attendance.'], 403);
        }

        $currentTeamId = Auth::user()->currentTeam->id;

        $attendance = Attendance::where('id', $id)
            ->where('team_id', $currentTeamId)
            ->with(['staff:id'])
            ->first();

        if (! $attendance) {
            return response()->json(['error' => 'Attendance record not found'], 404);
        }

        $staff = Staff::select('id', 'staff_name', 'salary_per_hr')
            ->where('team_id', $currentTeamId)
            ->orderBy('staff_name', 'asc')
            ->get();

        return response()->json([
            'attendance' => [
                'id' => $attendance->id,
                'staff_id' => $attendance->staff_id,
                'date' => $attendance->date,
                'duration' => $attendance->duration,
                'salary' => $attendance->salary,
                'task_description' => $attendance->task_description,
            ],
            'staffs' => $staff,
        ]);
    }

    public function store(Request $request)
    {
        $capability = $request->input('id') ? 'attendance.edit' : 'attendance.create';
        if (! Gate::allows($capability)) {
            return response()->json(['error' => 'You are not authorized to add or edit pulse_attendance.'], 403);
        }

        $currentTeamId = Auth::user()->currentTeam->id;

        $validated = $request->validate([
            'staff_id' => 'required|integer',
            'date' => 'required|date_format:d/m/Y',
            'duration' => 'required|numeric',
            'salary' => 'required|numeric',
            'task_description' => 'nullable|string',
        ]);

        // Convert the date format from d/m/Y to Y-m-d for storage
        $date = Carbon::createFromFormat(config('app.date_format'), $validated['date'])->format('Y-m-d');
        $validated['date'] = $date;

        // Check if the record is being updated or created
        $attendanceId = $request->input('id');

        // If existing record is found, update. Else, create.
        if ($attendanceId) {
            // If the given inputs do not validate, return error
            $attendance = Attendance::where('team_id', $currentTeamId)
                ->where('id', $attendanceId)
                ->first();

            if (! $attendance) {
                return response()->json(['error' => 'Attendance ID does not exist or you are not authorized.'], 422);
            }

            // Check for uniqueness if the `staff_id` or `date` are being updated
            $existingAttendance = Attendance::where('team_id', $currentTeamId)
                ->where('staff_id', $validated['staff_id'])
                ->where('date', $validated['date'])
                ->where('id', '<>', $attendanceId) // Exclude the current record
                ->exists();

            if ($existingAttendance) {
                return response()->json(['error' => 'An attendance entry for this staff and date already exists.'], 422);
            }

            $attendance->update($validated);
        } else {
            // If an existing record is found, return error
            $existingAttendance = Attendance::where('team_id', $currentTeamId)
                ->where('staff_id', $validated['staff_id'])
                ->where('date', $validated['date'])
                ->exists();

            if ($existingAttendance) {
                return response()->json(['error' => 'An attendance entry for this staff and date already exists.'], 422);
            }

            Attendance::create([
                'team_id' => $currentTeamId,
                'staff_id' => $validated['staff_id'],
                'date' => $validated['date'],
                'duration' => $validated['duration'],
                'salary' => $validated['salary'],
                'task_description' => $validated['task_description'] ?? null,
            ]);
        }

        return response()->json(['message' => 'Attendance saved successfully.'], 200);
    }

    public function getAttendanceData($date)
    {
        $teamId = Auth::user()->currentTeam->id;

        // Fetch active staff or those with existing attendance records for the date
        $staffsAttendance = Staff::leftJoin('pulse_attendance', function ($join) use ($date) {
            $join->on('staffs.id', '=', 'pulse_attendance.staff_id')
                ->where('pulse_attendance.date', $date);
        })
            ->select(
                'staffs.id as staff_id',
                'staffs.staff_name',
                'staffs.salary_per_hr',
                'staffs.is_active',
                'pulse_attendance.id as attendance_id',
                'pulse_attendance.duration',
                'pulse_attendance.salary',
                'pulse_attendance.task_description',
            )
            ->where(function ($query) {
                $query->where('staffs.is_active', 1)
                    ->orWhereNotNull('pulse_attendance.id');
            })
            ->where('staffs.team_id', $teamId)
            ->orderBy('staffs.staff_name', 'asc')
            ->get()
            ->map(function ($item) use ($teamId) {
                return [
                    'staff_id' => $item->staff_id,
                    'staff_name' => $item->staff_name,
                    'salary_per_hr' => $item->salary_per_hr,
                    'attendance_id' => $item->attendance_id,
                    'duration' => $item->duration ?? '',
                    'salary' => $item->salary ?? '',
                    'task_description' => $item->task_description ?? '',
                    'team_id' => $teamId, // Ensure team_id is set for attendance
                ];
            })->toArray();

        return response()->json($staffsAttendance);
    }

    public function saveAttendance(Request $request, $date)
    {
        if (! Gate::allows('attendance.edit')) {
            return response()->json(['error' => 'You are not authorized to save pulse_attendance.'], 403);
        }

        $teamId = Auth::user()->currentTeam->id;
        $staffsAttendance = $request->input('staffsAttendance', []);

        foreach ($staffsAttendance as $staffAttendance) {
            // Check if there’s an existing record that needs to be deleted
            if (empty($staffAttendance['duration']) || $staffAttendance['duration'] == 0) {
                Attendance::where('staff_id', $staffAttendance['staff_id'])
                    ->where('date', $date)
                    ->where('team_id', $teamId)
                    ->delete();

                continue; // Move to the next staff record
            }

            // Otherwise, create or update the attendance record
            Attendance::updateOrCreate(
                ['staff_id' => $staffAttendance['staff_id'], 'date' => $date],
                [
                    'duration' => $staffAttendance['duration'],
                    'salary' => $staffAttendance['salary'],
                    'task_description' => $staffAttendance['task_description'],
                    'team_id' => $teamId, // Set team_id explicitly for attendance
                ]
            );
        }

        return response()->json(['message' => 'Attendance records updated successfully.']);
    }

    public function hasRecords()
    {
        $teamId = Auth::user()->currentTeam->id;

        $exists = DB::table('pulse_attendance')
            ->where('team_id', $teamId)
            ->exists();

        return response()->json(['hasAttendance' => $exists]);
    }

    public function getAttendanceReport(Request $request)
    {
        Log::info('Received GET Params: ', $request->query());

        $start = Carbon::parse($request->query('start'))->startOfDay();
        $end = Carbon::parse($request->query('end'))->endOfDay();

        $teamId = Auth::user()->currentTeam->id;

        Log::info("Report Request: start=$start end=$end team=$teamId");

        $whereDate = function ($query) use ($start, $end) {
            $query->whereBetween(DB::raw('DATE(pulse_attendance.date)'), [
                $start->format('Y-m-d'),
                $end->format('Y-m-d'),
            ]);
        };

        $report = DB::table('pulse_attendance')
            ->join('staffs', 'pulse_attendance.staff_id', '=', 'staffs.id')
            ->where('pulse_attendance.team_id', $teamId)
            ->where('staffs.team_id', $teamId)
            ->where($whereDate)
            ->groupBy('pulse_attendance.staff_id', 'staffs.staff_name')
            ->select(
                'staffs.staff_name as staff_name',
                DB::raw('SUM(pulse_attendance.salary) as total_salary'),
                DB::raw('COUNT(DISTINCT pulse_attendance.date) as days_worked')
            )
            ->orderBy('staff_name', 'asc')
            ->get();

        return response()->json($report);
    }

    /**
     * Staff-wise attendance & salary report for the mobile app — the same
     * dataset the web Attendance/Salary report renders (active staff, present
     * / absent days, hours, salary). Always covers every active staff member;
     * the app filters by staff on the device.
     */
    public function getAttendanceReportSummary(Request $request, AttendanceExportService $exportService)
    {
        [$start, $end] = $this->reportRange($request);

        return response()->json(
            $exportService->buildReport(Auth::user()->currentTeam->id, $start, $end)
        );
    }

    /**
     * PDF of the report above, limited to `staff_ids` (comma separated) when
     * given. Same template as the web export.
     */
    public function exportAttendanceReportPdf(Request $request, AttendanceExportService $exportService)
    {
        [$start, $end] = $this->reportRange($request);
        $request->validate(['staff_ids' => ['nullable', 'string', 'regex:/^\d+(,\d+)*$/']]);
        $workerIds = $request->filled('staff_ids')
            ? array_map('intval', explode(',', $request->query('staff_ids')))
            : ['all'];

        $team = Auth::user()->currentTeam;
        $report = $exportService->buildReport($team->id, $start, $end, $workerIds);

        return Pdf::loadView('Pulse.livewire.reports.exports.attendance-report-pdf', [
            'report' => $report,
            'teamName' => $team->name ?? 'CoiioT',
        ])->setPaper('a4', 'portrait')
            ->download("attendance-salary-{$report['start_date']}-to-{$report['end_date']}.pdf");
    }

    private function reportRange(Request $request): array
    {
        $data = $request->validate([
            'start' => 'required|date_format:Y-m-d',
            'end' => 'required|date_format:Y-m-d|after_or_equal:start',
        ]);
        // AttendanceExportService takes Illuminate\Support\Carbon, not Carbon\Carbon.
        $start = \Illuminate\Support\Carbon::parse($data['start']);
        $end = \Illuminate\Support\Carbon::parse($data['end']);
        abort_if($start->diffInDays($end) > 366, 422, 'Choose a range of at most 367 days.');

        return [$start, $end];
    }
}
