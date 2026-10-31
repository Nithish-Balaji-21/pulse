<?php

namespace App\Livewire\Pulse\Reports\Inventory;

use App\Services\Pulse\InventoryExportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

class BalesInventoryReport extends Component
{
    public $teamId;

    public $startDate;

    public $endDate;

    public $presetLabel = 'Yesterday';

    public function mount()
    {
        $currentTeam = app()->has('current.team') ? app('current.team') : Auth::user()->currentTeam;
        if (! $currentTeam || ! $currentTeam->has_pith_module || ! $currentTeam->has_pith_module_bales) {
            return redirect('/dashboard');
        }

        if (! Auth::user()->can('view-bale-inventory')) {
            return redirect('/dashboard');
        }

        $this->teamId = $currentTeam->id;
        $yesterday = now()->subDay()->toDateString();

        $this->startDate = request('start_date') ?: session("global_report_start_date_{$this->teamId}", $yesterday);
        $this->endDate = request('end_date') ?: session("global_report_end_date_{$this->teamId}", $yesterday);
        $this->presetLabel = request('preset') ?: session("global_report_preset_{$this->teamId}", 'Yesterday');
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

    public function exportPdf(InventoryExportService $exportService)
    {
        $report = $exportService->buildReport(
            'bales',
            $this->teamId,
            Carbon::parse($this->startDate),
            Carbon::parse($this->endDate)
        );

        $pdf = Pdf::loadView('Pulse.livewire.reports.exports.inventory-report-pdf', [
            'report' => $report,
            'teamName' => Auth::user()->activeTeam()?->name ?? Auth::user()->currentTeam->name ?? 'CoiioT',
        ])->setPaper('a4', 'portrait');

        return response()->streamDownload(
            fn () => print ($pdf->output()),
            "bales-inventory-{$this->startDate}-to-{$this->endDate}.pdf"
        );
    }

    public function sendSummaryCard()
    {
        $this->dispatch('trigger-summary-card-export', reportName: 'bales-inventory', startDate: $this->startDate, endDate: $this->endDate);
    }

    public function render(InventoryExportService $exportService)
    {
        $reportData = $exportService->buildReport(
            'bales',
            $this->teamId,
            Carbon::parse($this->startDate),
            Carbon::parse($this->endDate)
        );

        return view('Pulse.livewire.reports.inventory.bales', [
            'reportData' => $reportData,
        ])->layout('layouts.app');
    }
}
