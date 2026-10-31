<?php

namespace App\Http\Controllers\Pulse\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Pulse\Attendance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $team = Auth::user()->currentTeam;

        if ($team->has_pith_module) {
            return redirect("/{$team->id}/dashboard/pith");
        }
        if ($team->has_fibre_module) {
            return redirect("/{$team->id}/dashboard/fibre");
        }
        if ($team->has_growbags_module) {
            return redirect("/{$team->id}/dashboard/growbags");
        }

        return redirect("/{$team->id}/dashboard/pith");
    }

    public function fetchAttandanceEvents($startDate, $endDate)
    {
        $currentTeamId = Auth::user()->currentTeam->id;

        // Attendance Hours and Salary
        $attendanceHrsAndSalary = Attendance::with('staff:id,staff_name')
            ->select('staff_id', DB::raw('SUM(salary) as total_salary'), DB::raw('SUM(duration) as total_hours'))
            ->join('staffs', 'staffs.id', '=', 'pulse_attendance.staff_id')
            ->where('pulse_attendance.team_id', $currentTeamId)
            ->whereBetween('pulse_attendance.date', [$startDate, $endDate])
            ->groupBy('staff_id')
            ->orderBy('total_salary', 'desc')
            ->get();

        $result = $attendanceHrsAndSalary->map(function ($attendance) {
            return [
                'staff_id' => $attendance->staff_id,
                'staff_name' => $attendance->staff->staff_name,
                'total_hours' => (int) $attendance->total_hours,
                'total_salary' => (int) $attendance->total_salary,
            ];
        });

        return $result;
    }
}
