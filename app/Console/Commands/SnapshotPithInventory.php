<?php

namespace App\Console\Commands;

use App\Models\Pulse\PithLocation;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SnapshotPithInventory extends Command
{
    protected $signature = 'pith:inventory-snapshot
                            {--date=  : Target date Y-m-d, defaults to today}
                            {--from= : Override start date for initial backfill Y-m-d}';

    protected $description = 'Write immutable daily pith inventory snapshot rows with forward-fill';

    public function handle(): int
    {
        $targetDate = Carbon::parse(
            $this->option('date') ?: now()->toDateString()
        )->toDateString();

        $this->info("pith:snapshot ▶  {$targetDate}");

        // Deactivated locations that still hold live loads keep their history written,
        // otherwise their stock silently drops out of the summary charts.
        $locations = PithLocation::query()
            ->activeOrHoldingStock()
            ->select('pulse_pith_locations.id', 'pulse_pith_locations.team_id')
            ->get();

        if ($locations->isEmpty()) {
            $this->warn('No locations to snapshot.');

            return 0;
        }

        foreach ($locations as $loc) {
            $this->processLocation($loc, $targetDate);
        }

        $this->info('pith:snapshot completed ✓');

        return 0;
    }

    private function processLocation(object $loc, string $targetDate): void
    {
        $anchorRow = DB::table('pulse_pith_inventory_summary')
            ->where('location_id', $loc->id)
            ->where('team_id', $loc->team_id)
            ->orderBy('summary_date', 'desc')
            ->select('summary_date', 'inward', 'outward', 'in_stock')
            ->first();

        if ($anchorRow) {
            $fromDate = Carbon::parse($anchorRow->summary_date)
                ->addDay()
                ->toDateString();

            // The carry-forward seed: what the last written row holds.
            $carryInward = (float) $anchorRow->inward;
            $carryOutward = (float) $anchorRow->outward;
            $carryInStock = (float) $anchorRow->in_stock;
        } else {
            $firstLoad = DB::table('pulse_pith_inventory')
                ->where('pith_location_id', $loc->id)
                ->where('is_archived', 0)
                ->min('date');

            $fromDate = $this->option('from') ?? $firstLoad;

            if (! $fromDate || $fromDate > $targetDate) {
                return;
            }

            $dailyLoads = DB::table('pulse_pith_inventory')
                ->where('pith_location_id', $loc->id)
                ->where('is_archived', 0)
                ->whereBetween('date', [$fromDate, $targetDate])
                ->select(
                    DB::raw('DATE(date) as load_date'),
                    DB::raw('SUM(cft_offloaded) as inward'),
                    DB::raw('SUM(taken_cft_calculated) as outward')
                )
                ->groupBy(DB::raw('DATE(date)'))
                ->orderBy('load_date')
                ->get();

            $loadMap = [];

            foreach ($dailyLoads as $load) {
                $loadMap[$load->load_date] = [
                    'inward' => (float) $load->inward,
                    'outward' => (float) $load->outward,
                ];
            }

            $rows = [];
            $now = now();

            $runningInward = 0;
            $runningOutward = 0;

            $cursor = Carbon::parse($fromDate);
            $target = Carbon::parse($targetDate);

            while ($cursor->lte($target)) {

                $dateKey = $cursor->toDateString();

                if (array_key_exists($dateKey, $loadMap)) {
                    $runningInward += $loadMap[$dateKey]['inward'];
                    $runningOutward += $loadMap[$dateKey]['outward'];
                }

                $rows[] = [
                    'summary_date' => $dateKey,
                    'team_id' => $loc->team_id,
                    'location_id' => $loc->id,
                    'inward' => round($runningInward, 2),
                    'outward' => round($runningOutward, 2),
                    'in_stock' => max(
                        0,
                        round($runningInward - $runningOutward, 2)
                    ),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $cursor->addDay();
            }

            $inserted = DB::table('pulse_pith_inventory_summary')
                ->insertOrIgnore($rows);

            $this->info(
                "loc {$loc->id}: prepared="
                .count($rows)
                .", inserted={$inserted}"
            );

            return;
        }
        if ($fromDate > $targetDate) {
            return;
        }
        $todaySumRow = DB::table('pulse_pith_inventory')
            ->where('pith_location_id', $loc->id)
            ->where('is_archived', 0)
            ->whereDate('date', '<=', $targetDate)
            ->select(
                DB::raw('ROUND(COALESCE(SUM(cft_offloaded),       0), 2) AS inward'),
                DB::raw('ROUND(COALESCE(SUM(taken_cft_calculated), 0), 2) AS outward')
            )
            ->first();

        $todayInward = $todaySumRow ? (float) $todaySumRow->inward : 0.0;
        $todayOutward = $todaySumRow ? (float) $todaySumRow->outward : 0.0;
        $todayInStock = max(0.0, round($todayInward - $todayOutward, 2));

        // NEW: per-day loads for the gap between the anchor and today,
        // so intermediate days accumulate instead of repeating the stale anchor.
        // Optimized: only run Query 2 if there is a gap of 1 or more intermediate days
        $gapDailyLoads = collect();
        if ($fromDate < $targetDate) {
            $gapDailyLoads = DB::table('pulse_pith_inventory')
                ->where('pith_location_id', $loc->id)
                ->where('is_archived', 0)
                ->whereBetween('date', [$fromDate, $targetDate])
                ->select(
                    DB::raw('DATE(date) as load_date'),
                    DB::raw('SUM(cft_offloaded) as inward'),
                    DB::raw('SUM(taken_cft_calculated) as outward')
                )
                ->groupBy(DB::raw('DATE(date)'))
                ->orderBy('load_date')
                ->get()
                ->keyBy('load_date');
        }

        $rows = [];
        $now = now();
        $cursor = Carbon::parse($fromDate);
        $target = Carbon::parse($targetDate);

        // Running totals start at the anchor seed and accumulate day by day.
        $runningInward = $carryInward;
        $runningOutward = $carryOutward;

        while ($cursor->lte($target)) {
            $dateKey = $cursor->toDateString();

            if ($dateKey === $targetDate) {
                // Target day: keep v1's fresh full re-sum untouched (self-heals edits/decrements).
                if ($todayInward > 0.0 || $todayOutward > 0.0) {
                    $rows[] = [
                        'summary_date' => $dateKey,
                        'team_id' => $loc->team_id,
                        'location_id' => $loc->id,
                        'inward' => $todayInward,
                        'outward' => $todayOutward,
                        'in_stock' => $todayInStock,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            } else {
                // Intermediate day: accumulate that day's own loads instead of repeating stale carry.
                if (isset($gapDailyLoads[$dateKey])) {
                    $runningInward += (float) $gapDailyLoads[$dateKey]->inward;
                    $runningOutward += (float) $gapDailyLoads[$dateKey]->outward;
                }

                if ($runningInward > 0.0 || $runningOutward > 0.0) {
                    $rows[] = [
                        'summary_date' => $dateKey,
                        'team_id' => $loc->team_id,
                        'location_id' => $loc->id,
                        'inward' => round($runningInward, 2),
                        'outward' => round($runningOutward, 2),
                        'in_stock' => max(0.0, round($runningInward - $runningOutward, 2)),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            $cursor->addDay();
        }

        if (empty($rows)) {
            return;
        }
        DB::table('pulse_pith_inventory_summary')->insertOrIgnore($rows);

        $this->line(
            "  loc {$loc->id}: {$fromDate} → {$targetDate}  "
            .count($rows).' row(s) written'
        );
    }
}
