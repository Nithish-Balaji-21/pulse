<?php

namespace App\Services\Pulse;

use App\Models\Team;
use App\Services\NotificationSenderService;
use App\Support\Notifications\NotificationType;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProductionSummaryService
{
    protected int $teamId;

    protected $team;

    public function __construct(int $teamId, ?Team $team = null)
    {
        $this->teamId = $teamId;
        $this->team = $team ?? Team::where('id', $teamId)->first();
    }

    public function getPithLoadsSummaryByShift($start, $end = null)
    {
        $end = $end ?? $start;

        return DB::table('pulse_summary_pith_productions')
            ->select('shift_name', DB::raw('SUM(cft_expected) as loads_expected'))
            ->whereBetween('shift_date', [$start, $end])
            ->where('team_id', $this->teamId)
            ->groupBy('shift_name')
            ->get()
            ->map(function ($row) {
                return (array) $row;
            })
            ->toArray();
    }

    public function getFibreBalesSummaryByShift($start, $end = null)
    {
        $end = $end ?? $start;

        return DB::table('pulse_summary_fibre_mega_bales')
            ->select(
                'shift_name',
                DB::raw('SUM(bales_produced) as bales_produced'),
                DB::raw('SUM(bales_weighed) as bales_weighed'),
                DB::raw('ROUND(SUM(weight) / 1000, 2) as weight')
            )
            ->whereBetween('shift_date', [$start, $end])
            ->where('team_id', $this->teamId)
            ->groupBy('shift_name')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->toArray();
    }

    public function getPithBlocksSummary($start, $end = null)
    {
        if (! $this->team->has_pith_module_blocks) {
            return 0;
        }

        $end = $end ?? $start;

        return DB::table('pulse_summary_pith_blocks')
            ->where('team_id', $this->teamId)
            ->whereBetween('shift_date', [$start, $end])
            ->sum('blocks_expected');
    }

    public function getSmallFibreBaleSummary($start, $end = null)
    {
        if (! $this->team->has_pith_module_bales) {
            return 0;
        }

        $end = $end ?? $start;

        return DB::table('pulse_summary_mini_bales')
            ->where('team_id', $this->teamId)
            ->whereBetween('shift_date', [$start, $end])
            ->sum('bales_expected');
    }

    public function getGrowBagsSummaryByShift($start, $end = null)
    {
        $end = $end ?? $start;

        return DB::table('pulse_summary_growbags')
            ->select(
                'shift_name',
                DB::raw('SUM(bags_produced) as bags_produced'),
                DB::raw('SUM(eb_units) as eb_units'),
                DB::raw('ROUND(SUM(eb_price), 1) as eb_price')
            )
            ->whereBetween('shift_date', [$start, $end])
            ->where('team_id', $this->teamId)
            ->groupBy('shift_name')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->toArray();
    }

    public function getWaterMeterSummary($start, $end = null)
    {
        if (! $this->team->has_water_meter_module) {
            return 0;
        }

        $end = $end ?? $start;

        $fieldId = config('app.water_consumption.total');
        $dailyConsumptionLimit = config('app.water_consumption.daily_consumption_limit');

        return DB::table('pulse_summary_water_meter')
            ->where('team_id', $this->teamId)
            ->where('field_id', $fieldId)
            ->whereBetween('date', [$start, $end])
            ->when($dailyConsumptionLimit, function ($query) use ($dailyConsumptionLimit) {
                $query->where('litres_consumed', '<=', $dailyConsumptionLimit);
            })
            ->sum('litres_consumed');
    }

    public function getAttendanceSummary($start, $end = null)
    {
        $end = $end ?? $start;

        $summary = DB::table('pulse_attendance')
            ->selectRaw('COUNT(*) as staff_count, SUM(duration) as hour_count, SUM(salary) as total_salary')
            ->where('team_id', $this->teamId)
            ->whereBetween('date', [$start, $end])
            ->first();

        return [
            'staff_count' => $summary->staff_count ?? 0,
            'hour_count' => $summary->hour_count ?? 0.0,
            'total_salary' => $summary->total_salary ?? 0.0,
        ];
    }

    public function getPithStockSummary()
    {
        $results = DB::table('pulse_pith_inventory')
            ->leftJoin('pulse_pith_locations', 'pulse_pith_inventory.pith_location_id', '=', 'pulse_pith_locations.id')
            ->where('pulse_pith_locations.team_id', $this->teamId)
            ->where('pulse_pith_inventory.is_archived', 0)
            ->select(
                'pulse_pith_inventory.pith_location_id',
                DB::raw('SUM(cft_offloaded) as total_offloaded'),
                DB::raw('SUM(taken_cft_calculated) as total_taken')
            )
            ->groupBy('pulse_pith_inventory.pith_location_id')
            ->get();

        $totalStock = $results->sum(function ($row) {
            return max(0, $row->total_offloaded - $row->total_taken);
        });

        return $totalStock ?? 0;
    }

    public function getHusksStockSummary()
    {
        if (! $this->team->has_husks_stock_module ?? true) {
            return 0;
        }

        return DB::table('pulse_husks_daily_stock')
            ->where('team_id', $this->teamId)
            ->orderByDesc('date')
            ->value('closing_stock') ?? 0;
    }

    public function buildSummaryAndNotify($mainModuleSummary, $subject, $start, $end = null, $type = 'pith', $notifyType = 'daily')
    {
        Log::info("Building production summary for team {$this->team->name} ({$this->teamId}) from {$start} to {$end} for type {$type}");

        $otherModulesSummary = [
            'fibre_bales' => $this->getSmallFibreBaleSummary($start, $end),
            'pith_blocks' => $this->getPithBlocksSummary($start, $end),
            'water' => $this->getWaterMeterSummary($start, $end),
            'attendance' => $this->getAttendanceSummary($start, $end),
            'pith_stock' => $this->getPithStockSummary(),
            'husks_stock' => $this->getHusksStockSummary(),
        ];

        $subject = "{$this->team->name} - {$subject}";

        $builder = app(ProductionSummaryBuilder::class, ['team' => $this->team]);
        $bodyLines = $builder->build($mainModuleSummary, $otherModulesSummary, $type, $notifyType);

        $notifier = app(NotificationSenderService::class);
        $notifier->notifyTeamUsers(
            $this->teamId,
            [],
            $subject,
            $bodyLines,
            $start,
            $end,
            false,
            NotificationType::forSummaryPeriod($notifyType),
            $this->buildPithInventoryAttachment($type, $notifyType, $start, $end)
        );
    }

    /**
     * PDF snapshot of the pith inventory locations ledger (same report as the
     * manual Filter & Export → PDF button), covering the same range as the
     * summary itself (a single day for daily, the full period for weekly/
     * monthly). Skipped when the team has no configured locations or the
     * period's closing stock doesn't clear 100 cft.
     *
     * The attachment is a convenience on top of the summary, so a failure to
     * render it must never take down the summary itself (or the device-data
     * job that triggered it) — we log and fall back to an email with no PDF.
     *
     * @return array{content_base64: string, filename: string, mime: string}|null
     */
    private function buildPithInventoryAttachment(string $type, string $notifyType, $start, $end = null): ?array
    {
        if ($type !== 'pith' || ! in_array($notifyType, ['daily', 'weekly', 'monthly'], true)) {
            return null;
        }

        try {
            $startDate = Carbon::parse($start);
            $endDate = $end ? Carbon::parse($end) : $startDate->copy();

            $report = app(PithInventoryExportService::class)->buildReport($this->teamId, $startDate, $endDate);

            if (empty($report['groups']) || $report['grand_total']['closing_stock'] <= 100) {
                return null;
            }

            $pdf = Pdf::loadView('pulse::livewire.pith.exports.inventory-report-pdf', [
                'report' => $report,
                'teamName' => $this->team->name,
            ])->setPaper('a4', 'portrait');

            $filename = $startDate->isSameDay($endDate)
                ? "pith-inventory-{$startDate->toDateString()}.pdf"
                : "pith-inventory-{$startDate->toDateString()}-to-{$endDate->toDateString()}.pdf";

            // Base64, not raw bytes: the mailable is queued, and Laravel JSON
            // encodes the queue payload — binary content fails with
            // "Unable to JSON encode payload. Error (5): Malformed UTF-8".
            return [
                'content_base64' => base64_encode($pdf->output()),
                'filename' => $filename,
                'mime' => 'application/pdf',
            ];
        } catch (\Throwable $e) {
            Log::error("Pith inventory attachment skipped for team {$this->teamId}: ".$e->getMessage());

            return null;
        }
    }
}
