<?php

namespace App\Livewire\Pulse\Attendance;

use App\Models\Pulse\Attendance;
use App\Models\Staff;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class AttendanceManager extends Component
{
    public $date;

    public $staffsAttendance = [];

    public $teamId;

    public function mount($date)
    {
        $this->date = $date;

        $this->teamId = app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id;

        // 📌 Fetch active staff or those with existing attendance
        $this->staffsAttendance = Staff::leftJoin('pulse_attendance', function ($join) {
            $join->on('staffs.id', '=', 'pulse_attendance.staff_id')
                ->where('pulse_attendance.date', $this->date);
        })
            ->select(
                'staffs.id as staff_id',
                'staffs.staff_name',
                'staffs.salary_per_hr',
                'staffs.is_active',
                'pulse_attendance.id as attendance_id',
                'pulse_attendance.duration',
                'pulse_attendance.salary',
                'pulse_attendance.task_description'
            )
            ->where(function ($query) {
                $query->where('staffs.is_active', 1)
                    ->orWhereNotNull('pulse_attendance.id');
            })
            ->where('staffs.team_id', $this->teamId)
            ->orderBy('staff_name', 'asc')
            ->get()
            ->map(function ($item) {
                return [
                    'staff_id' => $item->staff_id,
                    'staff_name' => $item->staff_name,
                    'salary_per_hr' => $item->salary_per_hr,
                    'attendance_id' => $item->attendance_id,
                    'duration' => $item->duration ?? '',
                    'salary' => $item->salary ?? '',
                    'task_description' => $item->task_description ?? '',
                    'team_id' => $this->teamId,
                ];
            })
            ->toArray();
    }

    public function updatedStaffsAttendance($value, $key)
    {
        [$index, $field] = explode('.', $key);

        if ($field === 'duration') {
            if (empty($this->staffsAttendance[$index]['duration'])) {
                $this->staffsAttendance[$index]['salary'] = null;
            } else {
                $this->staffsAttendance[$index]['salary'] = round($this->staffsAttendance[$index]['duration'] * $this->staffsAttendance[$index]['salary_per_hr']);
            }
        }
    }

    public function saveAttendance()
    {
        // The route carries can:attendance.edit, but Livewire updates post to
        // /livewire/update and never re-run it — this action is reachable on its
        // own, so it repeats the gate rather than trusting the page load.
        if (! Gate::allows('attendance.edit')) {
            session()->flash('error', 'You are not authorized to manage attendance records');

            return;
        }

        foreach ($this->staffsAttendance as $staffAttendance) {
            if (empty($staffAttendance['duration']) || $staffAttendance['duration'] == 0) {
                Attendance::where('staff_id', $staffAttendance['staff_id'])
                    ->where('date', $this->date)
                    ->where('team_id', $this->teamId)
                    ->delete();

                continue;
            }

            Attendance::updateOrCreate(
                ['staff_id' => $staffAttendance['staff_id'], 'date' => $this->date],
                [
                    'duration' => $staffAttendance['duration'],
                    'salary' => $staffAttendance['salary'],
                    'task_description' => $staffAttendance['task_description'],
                    'team_id' => $this->teamId,
                ]
            );
        }

        session()->flash('message', 'Attendance records updated successfully.');

        return redirect()->route('attendance.index', ['team' => $this->teamId, 'date' => $this->date]);
    }

    public function render()
    {
        return view('pulse::livewire.attendance.attendance-manager');
    }
}
