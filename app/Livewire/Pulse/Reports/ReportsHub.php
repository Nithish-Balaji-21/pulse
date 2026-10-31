<?php

namespace App\Livewire\Pulse\Reports;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

class ReportsHub extends Component
{
    public $teamId;

    public $team;

    public $startDate;

    public $endDate;

    public $presetLabel = 'Yesterday';

    public function mount()
    {
        $currentTeam = app()->has('current.team') ? app('current.team') : Auth::user()->currentTeam;
        if (! $currentTeam || ! $currentTeam->has_reports_module) {
            return redirect('/dashboard');
        }

        $this->teamId = $currentTeam->id;
        $this->team = $currentTeam;

        $yesterday = now()->subDay()->toDateString();
        $this->startDate = session("global_report_start_date_{$this->teamId}", $yesterday);
        $this->endDate = session("global_report_end_date_{$this->teamId}", $yesterday);
        $this->presetLabel = session("global_report_preset_{$this->teamId}", 'Yesterday');
    }

    #[On('date-range-updated')]
    public function handleDateRangeUpdate($startDate, $endDate, $preset)
    {
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->presetLabel = $preset;

        session([
            "global_report_start_date_{$this->teamId}" => $startDate,
            "global_report_end_date_{$this->teamId}" => $endDate,
            "global_report_preset_{$this->teamId}" => $preset,
        ]);
    }

    public function render()
    {
        return view('Pulse.livewire.reports.reports-hub', [
            // Husk is pith raw material — available whenever pith is on (not a separate Reports flag).
            'hasPith' => (bool) ($this->team->has_pith_module ?? false),
            'hasHusks' => (bool) ($this->team->has_pith_module ?? false),
            'hasBales' => (bool) ($this->team->has_pith_module_bales ?? false),
            'hasBlocks' => (bool) ($this->team->has_pith_module_blocks ?? false),
            'hasAttendance' => (bool) ($this->team->has_attendance_module ?? false),
        ])->layout('layouts.app');
    }
}
