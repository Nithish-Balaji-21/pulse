<?php

namespace App\Http\Controllers\Pulse;

use App\Http\Controllers\Controller;
use App\Services\Pulse\PithInventoryExportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class PithInventoryExportController extends Controller
{
    public function pdf(Request $request, PithInventoryExportService $exportService): Response
    {
        $team = Auth::user()->activeTeam();

        $validated = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'watering' => ['sometimes', 'boolean'],
            'empty' => ['sometimes', 'boolean'],
        ]);

        $startDate = Carbon::parse($validated['start_date']);
        $endDate = Carbon::parse($validated['end_date']);

        $report = $exportService->buildReport($team->id, $startDate, $endDate, (bool) ($validated['empty'] ?? false));

        $pdf = Pdf::loadView('pulse::livewire.pith.exports.inventory-report-pdf', [
            'report' => $report,
            'teamName' => $team->name,
            'showWatering' => (bool) ($validated['watering'] ?? false),
        ])->setPaper('a4', 'portrait');

        $filename = sprintf(
            'pith-inventory-%s-to-%s.pdf',
            $startDate->toDateString(),
            $endDate->toDateString(),
        );

        return $pdf->download($filename);
    }
}
