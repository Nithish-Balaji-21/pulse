<?php

namespace App\Services\Pulse;

use App\Models\Pulse\HusksDailyStock;
use App\Models\Pulse\MiniBaleProduction;
use App\Models\Pulse\PithBlocksProduction;
use Illuminate\Support\Carbon;

class InventoryExportService
{
    public function __construct(
        private readonly PithInventoryExportService $pithService
    ) {}

    /**
     * Build report dataset for pith, husk, bales, or blocks inventory.
     */
    public function buildReport(string $module, int $teamId, Carbon $startDate, Carbon $endDate): array
    {
        return match ($module) {
            'pith' => $this->pithService->buildReport($teamId, $startDate, $endDate),
            'husk' => $this->buildHuskReport($teamId, $startDate, $endDate),
            'bales' => $this->buildBalesReport($teamId, $startDate, $endDate),
            'blocks' => $this->buildBlocksReport($teamId, $startDate, $endDate),
            default => throw new \InvalidArgumentException("Invalid inventory module: {$module}"),
        };
    }

    private function buildHuskReport(int $teamId, Carbon $startDate, Carbon $endDate): array
    {
        $start = $startDate->copy()->startOfDay();
        $end = $endDate->copy()->startOfDay();

        $records = HusksDailyStock::where('team_id', $teamId)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('date', 'asc')
            ->get()
            ->keyBy(fn ($item) => $item->date->toDateString());

        $rows = [];
        $totalInward = 0.0;
        $totalConsumed = 0.0;
        $totalCorrection = 0.0;

        $cursor = $start->copy();
        $firstOpening = null;
        $runningBalance = 0.0;

        // Get initial opening from previous day's closing or the first record
        $prevClosing = HusksDailyStock::where('team_id', $teamId)
            ->whereDate('date', '<', $start->toDateString())
            ->orderByDesc('date')
            ->value('closing_stock');

        if ($prevClosing !== null) {
            $runningBalance = (float) $prevClosing;
        }

        while ($cursor->lte($end)) {
            $dayStr = $cursor->toDateString();
            $rec = $records->get($dayStr);

            if ($rec) {
                $opening = (float) ($rec->opening_stock ?? $runningBalance);
                $inward = (float) ($rec->husks_received ?? 0.0);
                $correction = (float) ($rec->husks_correction ?? 0.0);
                $closing = (float) ($rec->closing_stock ?? ($opening + $inward + $correction));
                $consumed = (float) ($opening + $inward + $correction - $closing);
                $runningBalance = $closing;
            } else {
                $opening = $runningBalance;
                $inward = 0.0;
                $correction = 0.0;
                $consumed = 0.0;
                $closing = $runningBalance;
            }

            if ($firstOpening === null) {
                $firstOpening = $opening;
            }

            $totalInward += $inward;
            $totalConsumed += $consumed;
            $totalCorrection += $correction;

            $rows[] = [
                'name' => $cursor->format('d/m/Y , D'),
                'month_key' => $cursor->format('Y-m'),
                'month_label' => $cursor->format('F Y'),
                'date' => $dayStr,
                'opening_stock' => $this->roundValue($opening),
                'inward' => $this->roundValue($inward),
                'correction' => $this->roundValue($correction),
                'correction_comment' => $rec?->correction_comment ?? null,
                'outward' => $this->roundValue($consumed),
                'closing_stock' => $this->roundValue($closing),
                'has_movement' => ($inward > 0 || $consumed > 0 || $correction != 0),
                'was_closed_in_range' => false,
            ];

            $cursor->addDay();
        }

        $daysCount = max(1, $start->diffInDays($end) + 1);

        // If range exceeds 30 days, roll up rows by month
        if ($daysCount > 30) {
            $monthGroups = [];
            foreach ($rows as $r) {
                $mKey = $r['month_key'];
                if (! isset($monthGroups[$mKey])) {
                    $monthGroups[$mKey] = [
                        'base_label' => $r['month_label'],
                        'month_key' => $mKey,
                        'days_in_month' => 0,
                        'opening_stock' => $r['opening_stock'],
                        'inward' => 0.0,
                        'correction' => 0.0,
                        'outward' => 0.0,
                        'closing_stock' => $r['closing_stock'],
                        'has_movement' => false,
                    ];
                }
                $monthGroups[$mKey]['days_in_month']++;
                $monthGroups[$mKey]['inward'] += $r['inward'];
                $monthGroups[$mKey]['correction'] += $r['correction'];
                $monthGroups[$mKey]['outward'] += $r['outward'];
                $monthGroups[$mKey]['closing_stock'] = $r['closing_stock'];
                if ($r['has_movement']) {
                    $monthGroups[$mKey]['has_movement'] = true;
                }
            }

            foreach ($monthGroups as &$mg) {
                $mg['name'] = "{$mg['base_label']} ({$mg['days_in_month']} ".($mg['days_in_month'] === 1 ? 'day' : 'days').')';
                unset($mg['base_label']);
            }
            unset($mg);

            $rows = array_values($monthGroups);
        }

        $totals = [
            'opening_stock' => $this->roundValue($firstOpening ?? 0.0),
            'inward' => $this->roundValue($totalInward),
            'correction' => $this->roundValue($totalCorrection),
            'outward' => $this->roundValue($totalConsumed),
            'closing_stock' => $this->roundValue($runningBalance),
            'write_off_in_range' => 0.0,
        ];

        return [
            'module' => 'husk',
            'module_title' => 'Husk Inventory Report',
            'unit_label' => 'CFT',
            'inward_label' => 'Received',
            'outward_label' => 'Consumed',
            'team_id' => $teamId,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'generated_at' => now(),
            'days_count' => $daysCount,
            'is_month_view' => $daysCount > 30,
            'rows' => $rows,
            'groups' => [
                [
                    'name' => $daysCount > 30 ? 'Monthly Husk Ledger' : 'Day-wise Husk Ledger',
                    'location_count' => count($rows),
                    'locations' => $rows,
                    'subtotal' => $totals,
                ],
            ],
            'grand_total' => $totals,
            'totals' => $totals,
        ];
    }

    private function buildBalesReport(int $teamId, Carbon $startDate, Carbon $endDate): array
    {
        $start = $startDate->copy()->startOfDay();
        $end = $endDate->copy()->startOfDay();

        $records = MiniBaleProduction::where('team_id', $teamId)
            ->whereBetween('shift_date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('shift_date', 'asc')
            ->get()
            ->keyBy(fn ($item) => Carbon::parse($item->shift_date)->toDateString());

        $prevClosing = MiniBaleProduction::where('team_id', $teamId)
            ->whereDate('shift_date', '<', $start->toDateString())
            ->orderByDesc('shift_date')
            ->value('bales_in_stock');

        $runningBalance = $prevClosing !== null ? (int) $prevClosing : 0;
        $rangeOpeningStock = $runningBalance;

        $rows = [];
        $totalProduced = 0;
        $totalSold = 0;
        $totalCorrection = 0;
        $firstOpening = null;

        $cursor = $start->copy();

        while ($cursor->lte($end)) {
            $dayStr = $cursor->toDateString();
            $rec = $records->get($dayStr);

            if ($rec) {
                $opening = $runningBalance;
                $produced = $rec->producedForStock();
                $sold = (int) ($rec->sold ?? 0);
                $correction = (int) ($rec->correction ?? 0);
                $closing = (int) ($rec->bales_in_stock ?? ($opening + $produced + $correction - $sold));
                $runningBalance = $closing;
            } else {
                $opening = $runningBalance;
                $produced = 0;
                $sold = 0;
                $correction = 0;
                $closing = $runningBalance;
            }

            if ($firstOpening === null) {
                $firstOpening = $opening;
            }

            $totalProduced += $produced;
            $totalSold += $sold;
            $totalCorrection += $correction;

            $rows[] = [
                'name' => $cursor->format('d/m/Y , D'),
                'month_key' => $cursor->format('Y-m'),
                'month_label' => $cursor->format('F Y'),
                'date' => $dayStr,
                'opening_stock' => $opening,
                'inward' => $produced,
                'produced' => $produced,
                'correction' => $correction,
                'correction_comment' => $rec?->correction_comment ?? null,
                'outward' => $sold,
                'sold' => $sold,
                'closing_stock' => $closing,
                'has_movement' => ($produced > 0 || $sold > 0 || $correction != 0),
                'was_closed_in_range' => false,
            ];

            $cursor->addDay();
        }

        $daysCount = max(1, $start->diffInDays($end) + 1);

        // If range exceeds 30 days, roll up rows by month
        if ($daysCount > 30) {
            $monthGroups = [];
            foreach ($rows as $r) {
                $mKey = $r['month_key'];
                if (! isset($monthGroups[$mKey])) {
                    $monthGroups[$mKey] = [
                        'base_label' => $r['month_label'],
                        'month_key' => $mKey,
                        'days_in_month' => 0,
                        'opening_stock' => $r['opening_stock'],
                        'inward' => 0,
                        'produced' => 0,
                        'correction' => 0,
                        'outward' => 0,
                        'sold' => 0,
                        'closing_stock' => $r['closing_stock'],
                        'has_movement' => false,
                    ];
                }
                $monthGroups[$mKey]['days_in_month']++;
                $monthGroups[$mKey]['inward'] += $r['inward'];
                $monthGroups[$mKey]['produced'] += $r['produced'];
                $monthGroups[$mKey]['correction'] += $r['correction'];
                $monthGroups[$mKey]['outward'] += $r['outward'];
                $monthGroups[$mKey]['sold'] += $r['sold'];
                $monthGroups[$mKey]['closing_stock'] = $r['closing_stock'];
                if ($r['has_movement']) {
                    $monthGroups[$mKey]['has_movement'] = true;
                }
            }

            foreach ($monthGroups as &$mg) {
                $mg['name'] = "{$mg['base_label']} ({$mg['days_in_month']} ".($mg['days_in_month'] === 1 ? 'day' : 'days').')';
                unset($mg['base_label']);
            }
            unset($mg);

            $rows = array_values($monthGroups);
        }

        $totals = [
            'opening_stock' => $firstOpening ?? $rangeOpeningStock,
            'inward' => $totalProduced,
            'produced' => $totalProduced,
            'correction' => $totalCorrection,
            'outward' => $totalSold,
            'sold' => $totalSold,
            'closing_stock' => $runningBalance,
            'write_off_in_range' => 0.0,
        ];

        return [
            'module' => 'bales',
            'module_title' => 'Bales Inventory Report',
            'unit_label' => 'bales',
            'inward_label' => 'Produced',
            'outward_label' => 'Sold',
            'team_id' => $teamId,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'generated_at' => now(),
            'days_count' => $daysCount,
            'is_month_view' => $daysCount > 30,
            'rows' => $rows,
            'groups' => [
                [
                    'name' => $daysCount > 30 ? 'Monthly Bales Ledger' : 'Bales Inventory Ledger',
                    'location_count' => count($rows),
                    'locations' => $rows,
                    'subtotal' => $totals,
                ],
            ],
            'grand_total' => $totals,
            'totals' => $totals,
        ];
    }

    private function buildBlocksReport(int $teamId, Carbon $startDate, Carbon $endDate): array
    {
        $start = $startDate->copy()->startOfDay();
        $end = $endDate->copy()->startOfDay();

        $records = PithBlocksProduction::where('team_id', $teamId)
            ->whereBetween('shift_date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('shift_date', 'asc')
            ->get()
            ->keyBy(fn ($item) => Carbon::parse($item->shift_date)->toDateString());

        $prevClosing = PithBlocksProduction::where('team_id', $teamId)
            ->whereDate('shift_date', '<', $start->toDateString())
            ->orderByDesc('shift_date')
            ->value('blocks_in_stock');

        $runningBalance = $prevClosing !== null ? (int) $prevClosing : 0;
        $rangeOpeningStock = $runningBalance;

        $rows = [];
        $totalProduced = 0;
        $totalSold = 0;
        $totalCorrection = 0;
        $firstOpening = null;

        $cursor = $start->copy();

        while ($cursor->lte($end)) {
            $dayStr = $cursor->toDateString();
            $rec = $records->get($dayStr);

            if ($rec) {
                $opening = $runningBalance;
                $produced = $rec->producedForStock();
                $sold = (int) ($rec->sold ?? 0);
                $correction = (int) ($rec->correction ?? 0);
                $closing = (int) ($rec->blocks_in_stock ?? ($opening + $produced + $correction - $sold));
                $runningBalance = $closing;
            } else {
                $opening = $runningBalance;
                $produced = 0;
                $sold = 0;
                $correction = 0;
                $closing = $runningBalance;
            }

            if ($firstOpening === null) {
                $firstOpening = $opening;
            }

            $totalProduced += $produced;
            $totalSold += $sold;
            $totalCorrection += $correction;

            $rows[] = [
                'name' => $cursor->format('d/m/Y , D'),
                'month_key' => $cursor->format('Y-m'),
                'month_label' => $cursor->format('F Y'),
                'date' => $dayStr,
                'opening_stock' => $opening,
                'inward' => $produced,
                'produced' => $produced,
                'correction' => $correction,
                'correction_comment' => $rec?->correction_comment ?? null,
                'outward' => $sold,
                'sold' => $sold,
                'closing_stock' => $closing,
                'has_movement' => ($produced > 0 || $sold > 0 || $correction != 0),
                'was_closed_in_range' => false,
            ];

            $cursor->addDay();
        }

        $daysCount = max(1, $start->diffInDays($end) + 1);

        // If range exceeds 30 days, roll up rows by month
        if ($daysCount > 30) {
            $monthGroups = [];
            foreach ($rows as $r) {
                $mKey = $r['month_key'];
                if (! isset($monthGroups[$mKey])) {
                    $monthGroups[$mKey] = [
                        'base_label' => $r['month_label'],
                        'month_key' => $mKey,
                        'days_in_month' => 0,
                        'opening_stock' => $r['opening_stock'],
                        'inward' => 0,
                        'produced' => 0,
                        'correction' => 0,
                        'outward' => 0,
                        'sold' => 0,
                        'closing_stock' => $r['closing_stock'],
                        'has_movement' => false,
                    ];
                }
                $monthGroups[$mKey]['days_in_month']++;
                $monthGroups[$mKey]['inward'] += $r['inward'];
                $monthGroups[$mKey]['produced'] += $r['produced'];
                $monthGroups[$mKey]['correction'] += $r['correction'];
                $monthGroups[$mKey]['outward'] += $r['outward'];
                $monthGroups[$mKey]['sold'] += $r['sold'];
                $monthGroups[$mKey]['closing_stock'] = $r['closing_stock'];
                if ($r['has_movement']) {
                    $monthGroups[$mKey]['has_movement'] = true;
                }
            }

            foreach ($monthGroups as &$mg) {
                $mg['name'] = "{$mg['base_label']} ({$mg['days_in_month']} ".($mg['days_in_month'] === 1 ? 'day' : 'days').')';
                unset($mg['base_label']);
            }
            unset($mg);

            $rows = array_values($monthGroups);
        }

        $totals = [
            'opening_stock' => $firstOpening ?? $rangeOpeningStock,
            'inward' => $totalProduced,
            'produced' => $totalProduced,
            'correction' => $totalCorrection,
            'outward' => $totalSold,
            'sold' => $totalSold,
            'closing_stock' => $runningBalance,
            'write_off_in_range' => 0.0,
        ];

        return [
            'module' => 'blocks',
            'module_title' => 'Blocks Inventory Report',
            'unit_label' => 'blocks',
            'inward_label' => 'Produced',
            'outward_label' => 'Sold',
            'team_id' => $teamId,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'generated_at' => now(),
            'days_count' => $daysCount,
            'is_month_view' => $daysCount > 30,
            'rows' => $rows,
            'groups' => [
                [
                    'name' => $daysCount > 30 ? 'Monthly Blocks Ledger' : 'Blocks Inventory Ledger',
                    'location_count' => count($rows),
                    'locations' => $rows,
                    'subtotal' => $totals,
                ],
            ],
            'grand_total' => $totals,
            'totals' => $totals,
        ];
    }

    private function roundValue(float $value): float
    {
        $rounded = round($value, 2);

        return $rounded === 0.0 ? 0.0 : $rounded;
    }
}
