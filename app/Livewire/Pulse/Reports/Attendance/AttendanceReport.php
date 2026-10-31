<?php

namespace App\Livewire\Pulse\Reports\Attendance;

use App\Models\Staff;
use App\Services\Pulse\AttendanceExportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

class AttendanceReport extends Component
{
    public $teamId;

    // Filter controls (before clicking Run Report)
    public $startDate;

    public $endDate;

    public $presetLabel = 'Yesterday';

    public $selectedWorkers = ['all'];

    public $workerSearch = '';

    public function mount()
    {
        $currentTeam = app()->has('current.team') ? app('current.team') : Auth::user()->currentTeam;
        if (! $currentTeam || ! $currentTeam->has_attendance_module) {
            return redirect('/dashboard');
        }

        if (! Auth::user()->can('attendance.salary_report')) {
            return redirect('/dashboard');
        }

        $this->teamId = $currentTeam->id;
        $yesterday = now()->subDay()->toDateString();

        $this->startDate = request('start_date') ?: session("global_report_start_date_{$this->teamId}", $yesterday);
        $this->endDate = request('end_date') ?: session("global_report_end_date_{$this->teamId}", $yesterday);
        $this->presetLabel = request('preset') ?: session("global_report_preset_{$this->teamId}", 'Yesterday');
    }

    #[On('date-range-updated')]
    public function handleDateRangeUpdate($startDate, $endDate, $preset = 'Custom')
    {
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->presetLabel = $preset ?: 'Custom';

        session([
            "global_report_start_date_{$this->teamId}" => $startDate,
            "global_report_end_date_{$this->teamId}" => $endDate,
            "global_report_preset_{$this->teamId}" => $this->presetLabel,
        ]);
    }

    public function selectAllWorkers()
    {
        $this->selectedWorkers = ['all'];
    }

    public function unselectAllWorkers()
    {
        $this->selectedWorkers = [];
    }

    public function toggleWorker($staffId)
    {
        $staffId = (int) $staffId;
        if (in_array('all', $this->selectedWorkers, true)) {
            $allStaffIds = Staff::where('team_id', $this->teamId)->where('is_active', 1)->pluck('id')->toArray();
            $this->selectedWorkers = array_values(array_diff($allStaffIds, [$staffId]));
        } else {
            if (in_array($staffId, $this->selectedWorkers, true)) {
                $this->selectedWorkers = array_values(array_diff($this->selectedWorkers, [$staffId]));
            } else {
                $this->selectedWorkers[] = $staffId;
            }
        }
    }

    public function exportPdf(AttendanceExportService $exportService)
    {
        $report = $exportService->buildReport(
            $this->teamId,
            Carbon::parse($this->startDate),
            Carbon::parse($this->endDate),
            $this->selectedWorkers
        );

        $pdf = Pdf::loadView('Pulse.livewire.reports.exports.attendance-report-pdf', [
            'report' => $report,
            'teamName' => Auth::user()->activeTeam()?->name ?? Auth::user()->currentTeam->name ?? 'CoiioT',
        ])->setPaper('a4', 'portrait');

        return response()->streamDownload(
            fn () => print ($pdf->output()),
            "attendance-salary-{$this->startDate}-to-{$this->endDate}.pdf"
        );
    }

    public function render(AttendanceExportService $exportService)
    {
        $allActiveStaff = Staff::where('team_id', $this->teamId)
            ->where('is_active', 1)
            ->orderBy('staff_name', 'asc')
            ->get(['id', 'staff_name', 'is_active']);

        $totalActiveStaffCount = $allActiveStaff->count();

        $staffList = $allActiveStaff;
        if (! empty($this->workerSearch)) {
            $search = strtolower($this->workerSearch);
            $staffList = $staffList->filter(fn ($s) => str_contains(strtolower($s->staff_name), $search));
        }

        $reportData = $exportService->buildReport(
            $this->teamId,
            Carbon::parse($this->startDate),
            Carbon::parse($this->endDate),
            $this->selectedWorkers
        );

        return view('Pulse.livewire.reports.attendance.attendance', [
            'reportData' => $reportData,
            'staffList' => $staffList,
            'totalActiveStaffCount' => $totalActiveStaffCount,
        ])->layout('layouts.app');
    }
}
