<?php

namespace App\Console\Commands\Pulse;

use App\Models\Pulse\MiniBaleProduction;
use App\Models\Pulse\PithBlocksProduction;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * BackfillFinishedGoodsStock — Dedicated one-time backfill command for Finished Goods Stock Ledgers.
 *
 * Populates pulse_summary_mini_bales and pulse_summary_pith_blocks stock fields with configured sales dispatches
 * and physical audit corrections without re-running full operator actuals or attendance.
 *
 * Usage:
 *   php artisan demo:backfill-finished-goods --start-date=2026-01-01 --end-date=2026-08-10
 */
class BackfillFinishedGoodsStock extends Command
{
    protected $signature = 'demo:backfill-finished-goods
                            {--start-date= : Start date Y-m-d (defaults to 2026-01-01)}
                            {--end-date=   : End date Y-m-d (defaults to today)}
                            {--team=       : Specific team ID (optional)}';

    protected $description = 'One-time backfill tool for Fibre Bales and Pith Blocks daily stock ledgers';

    public function handle(): int
    {
        $startDateOpt = $this->option('start-date') ?: '2026-01-01';
        $endDateOpt = $this->option('end-date') ?: now()->toDateString();
        $specifiedTeam = $this->option('team') ? (int) $this->option('team') : null;

        $start = Carbon::parse($startDateOpt)->startOfDay();
        $end = Carbon::parse($endDateOpt)->startOfDay();

        if ($start->gt($end)) {
            $this->error('❌ Start date cannot be after end date.');

            return self::FAILURE;
        }

        // Resolve teams dynamically from pulse_devices or specified team
        if ($specifiedTeam) {
            $teamsToProcess = [$specifiedTeam];
        } else {
            $teamsToProcess = DB::table('pulse_devices')
                ->whereIn('module', ['fibre', 'pith', 'growbags'])
                ->pluck('team_id')
                ->unique()
                ->toArray();
        }

        $this->info('=========================================================================');
        $this->info(' Dedicated Finished Goods Stock Ledger Backfill (Bales & Blocks)');
        $this->info(' Teams: '.implode(', ', $teamsToProcess)." | Period: {$start->toDateString()} to {$end->toDateString()}");
        $this->info("=========================================================================\n");

        $period = CarbonPeriod::create($start, $end);
        $now = now();

        foreach ($period as $dt) {
            $dateStr = $dt->toDateString();

            foreach ($teamsToProcess as $teamId) {
                // 1. Fibre Bales summary stock (Bales sales 50 - 120, 57% of days)
                if (DB::table('pulse_summary_mini_bales')->where('team_id', $teamId)->whereDate('shift_date', $dateStr)->exists()) {
                    mt_srand(crc32($dateStr.'bales_sales_'.$teamId));
                    $hasSales = (mt_rand(1, 100) <= 57);
                    $sold = $hasSales ? mt_rand(50, 120) : null;

                    $hasCorrection = (mt_rand(1, 100) <= 5);
                    $correction = 0;
                    $comment = null;
                    if ($hasCorrection) {
                        $correction = mt_rand(-10, 10);
                        $comments = [
                            'Physical stock audit adjustment',
                            'Dispatch tally correction',
                            'Damaged bale write-off',
                            'Yard count reconciliation',
                        ];
                        $comment = $comments[array_rand($comments)];
                    }
                    mt_srand();

                    DB::table('pulse_summary_mini_bales')
                        ->where('team_id', $teamId)
                        ->whereDate('shift_date', $dateStr)
                        ->update([
                            'sold' => $sold,
                            'correction' => $correction,
                            'correction_comment' => $comment,
                            'updated_at' => $now,
                        ]);
                }

                // 2. Pith Blocks summary stock (Blocks sales 500 - 800, 57% of days)
                if (DB::table('pulse_summary_pith_blocks')->where('team_id', $teamId)->whereDate('shift_date', $dateStr)->exists()) {
                    mt_srand(crc32($dateStr.'blocks_sales_'.$teamId));
                    $hasSales = (mt_rand(1, 100) <= 57);
                    $sold = $hasSales ? mt_rand(500, 800) : null;

                    $hasCorrection = (mt_rand(1, 100) <= 5);
                    $correction = 0;
                    $comment = null;
                    if ($hasCorrection) {
                        $correction = mt_rand(-20, 20);
                        $comments = [
                            'Physical stock audit adjustment',
                            'Pallet dispatch count adjustment',
                            'Chipped/damaged block write-off',
                            'Monthly inventory audit correction',
                        ];
                        $comment = $comments[array_rand($comments)];
                    }
                    mt_srand();

                    DB::table('pulse_summary_pith_blocks')
                        ->where('team_id', $teamId)
                        ->whereDate('shift_date', $dateStr)
                        ->update([
                            'sold' => $sold,
                            'correction' => $correction,
                            'correction_comment' => $comment,
                            'updated_at' => $now,
                        ]);
                }
            }
        }

        // Reconcile ledgers in a single pass across the period for each team
        $startDateStr = $start->toDateString();
        foreach ($teamsToProcess as $teamId) {
            if (DB::table('pulse_summary_mini_bales')->where('team_id', $teamId)->exists()) {
                MiniBaleProduction::reconcileInStock($teamId, $startDateStr);
                $this->info(" ✔ Fibre Bales ledger reconciled from {$startDateStr} for Team {$teamId}");
            }
            if (DB::table('pulse_summary_pith_blocks')->where('team_id', $teamId)->exists()) {
                PithBlocksProduction::reconcileInStock($teamId, $startDateStr);
                $this->info(" ✔ Pith Blocks stock reconciled from {$startDateStr} for Team {$teamId}");
            }
        }

        $this->info("\n=========================================================================");
        $this->info(' ✔ SUCCESS: Finished goods daily stock ledgers backfilled successfully!');
        $this->info("=========================================================================\n");

        return self::SUCCESS;
    }
}
