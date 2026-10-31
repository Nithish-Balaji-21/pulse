<?php

namespace App\Console\Commands\Pulse;

use App\Models\Pulse\MiniBaleProduction;
use App\Models\Pulse\PithBlocksProduction;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * SimulateDemoOperatorActuals — Virtual Plant Supervisor Simulator for Demo Mode.
 *
 * Simulates a plant manager entering shift actuals (cft_produced), offloading loads
 * to drying yard locations (pulse_pith_inventory), and balancing husk daily stock.
 *
 * Usage:
 *   php artisan demo:simulate-actuals --date=2026-07-24
 *   php artisan demo:simulate-actuals --start-date=2026-07-23 --end-date=2026-07-26
 */
class SimulateDemoOperatorActuals extends Command
{
    protected $signature = 'demo:simulate-actuals
                            {--date=        : Single date Y-m-d}
                            {--start-date=  : Start date Y-m-d}
                            {--end-date=    : End date Y-m-d}
                            {--team=        : Team ID (defaults to pith team resolved from pulse_devices)}';

    protected $description = 'Simulates virtual supervisor submitting shift actuals and yard inventory offloads in demo mode';

    private const PC_HUSKS_PER_CFT = 1.89; // CFT of husks consumed per CFT of pith produced

    /** @var int Fibre team ID, resolved from pulse_devices at runtime for off-day logic */
    private int $fibreTeamId;

    public function handle(): int
    {
        // Resolve all demo team IDs dynamically from pulse_devices
        $devices = DB::table('pulse_devices')
            ->whereIn('module', ['fibre', 'pith', 'growbags'])
            ->select('team_id', 'module')
            ->get()
            ->keyBy('module');

        $this->fibreTeamId = (int) ($devices->get('fibre')?->team_id
            ?? throw new \RuntimeException('No device found in pulse_devices with module=fibre'));

        $pithTeamId = (int) ($devices->get('pith')?->team_id
            ?? throw new \RuntimeException('No device found in pulse_devices with module=pith'));

        $growBagsTeamId = (int) ($devices->get('growbags')?->team_id
            ?? throw new \RuntimeException('No device found in pulse_devices with module=growbags'));

        $specifiedTeam = $this->option('team') ? (int) $this->option('team') : null;
        $teamsToProcess = $specifiedTeam ? [$specifiedTeam] : array_unique([$pithTeamId, $this->fibreTeamId, $growBagsTeamId]);

        $dateOpt = $this->option('date');
        $startDateOpt = $this->option('start-date');
        $endDateOpt = $this->option('end-date');

        if ($dateOpt) {
            $start = Carbon::parse($dateOpt)->startOfDay();
            $end = Carbon::parse($dateOpt)->startOfDay();
        } elseif ($startDateOpt && $endDateOpt) {
            $start = Carbon::parse($startDateOpt)->startOfDay();
            $end = Carbon::parse($endDateOpt)->startOfDay();
        } else {
            $start = Carbon::yesterday()->startOfDay();
            $end = Carbon::today()->startOfDay();
        }

        if ($start->gt($end)) {
            $this->error('❌ Start date cannot be after end date.');

            return self::FAILURE;
        }

        $this->info('=========================================================================');
        $this->info(' Virtual Supervisor: Simulating Operator Shift Actuals, Labour & Expenses');
        $this->info(' Teams: '.implode(', ', $teamsToProcess)." | Period: {$start->toDateString()} to {$end->toDateString()}");
        $this->info("=========================================================================\n");

        $period = CarbonPeriod::create($start, $end);

        foreach ($period as $dt) {
            $dateStr = $dt->toDateString();
            $this->info("Processing virtual operator entries for date: {$dateStr}...");

            foreach ($teamsToProcess as $teamId) {
                // Modules specific to Pith Team
                if ($teamId === $pithTeamId) {
                    $this->ensurePithLocationsExist($teamId);
                    $totalCftProduced = $this->processPithShiftActuals($dateStr, $teamId);
                    $this->processPithBlocksShiftActuals($dateStr, $teamId);
                    $this->processFibreBalesShiftActuals($dateStr, $teamId);
                    $this->processPithYardInventory($dateStr, $teamId);
                    $this->processHuskDailyStock($dateStr, $totalCftProduced, $teamId);
                }

                // Finished Goods (Bales & Blocks) Daily Stock Ledgers & Sales simulation
                $this->processFinishedGoodsDailyStock($dateStr, $teamId);

                // Labour (Attendance) simulated for ALL factories (Fibre, Pith, GrowBags)
                $this->processAttendance($dateStr, $teamId);
            }
        }

        $this->info("\n=========================================================================");
        $this->info(' ✔ SUCCESS: Virtual supervisor actuals & inventory offloads completed!');
        $this->info("=========================================================================\n");

        return self::SUCCESS;
    }

    /**
     * Ensures the 8 Pith Drying Yard locations exist in pulse_pith_locations for the team.
     */
    private function ensurePithLocationsExist(int $teamId): void
    {
        $locations = [
            ['id' => 1, 'team_id' => $teamId, 'name' => 'Main Yard', 'is_active' => 1, 'low_stock_alert_value' => 500],
            ['id' => 2, 'team_id' => $teamId, 'name' => 'Secondary Yard', 'is_active' => 1, 'low_stock_alert_value' => 300],
            ['id' => 3, 'team_id' => $teamId, 'name' => 'Drying Yard A', 'is_active' => 1, 'low_stock_alert_value' => 300],
            ['id' => 4, 'team_id' => $teamId, 'name' => 'Drying Yard B', 'is_active' => 1, 'low_stock_alert_value' => 300],
            ['id' => 5, 'team_id' => $teamId, 'name' => 'Storage Shed 1', 'is_active' => 1, 'low_stock_alert_value' => 200],
            ['id' => 6, 'team_id' => $teamId, 'name' => 'Storage Shed 2', 'is_active' => 1, 'low_stock_alert_value' => 200],
            ['id' => 7, 'team_id' => $teamId, 'name' => 'Buffer Yard', 'is_active' => 1, 'low_stock_alert_value' => 300],
            ['id' => 8, 'team_id' => $teamId, 'name' => 'Finished Pith Yard', 'is_active' => 1, 'low_stock_alert_value' => 400],
        ];

        DB::table('pulse_pith_locations')->upsert($locations, ['id'], ['name', 'is_active', 'low_stock_alert_value']);
    }

    /**
     * Populate cft_produced for shifts where expected CFT was calculated by ProcessDeviceDataForPithJob.
     */
    private function processPithShiftActuals(string $date, int $teamId): float
    {
        $productions = DB::table('pulse_summary_pith_productions')
            ->where('team_id', $teamId)
            ->where('shift_date', $date)
            ->get();

        if ($productions->isEmpty()) {
            $this->info("  Pith actuals: No production summary records found for {$date}.");

            return 0.0;
        }

        $staffIds = DB::table('staffs')->where('team_id', $teamId)->where('is_active', 1)->pluck('id')->toArray();
        if (count($staffIds) < 2) {
            $staffIds = [123, 124];
        }
        $staff1 = $staffIds[0];
        $staff2 = $staffIds[1] ?? $staffIds[0];

        $day = Carbon::parse($date);
        $weekNumber = (int) $day->format('W');
        $isEvenWeek = ($weekNumber % 2 === 0);

        $totalCft = 0.0;

        foreach ($productions as $prod) {
            $cftExpected = (float) $prod->cft_expected;

            if ($cftExpected <= 0) {
                continue;
            }

            if ($prod->shift_name === 'Shift 1') {
                $staffId = $isEvenWeek ? $staff1 : $staff2;
                $maxHrs = 10.0;
            } else {
                $staffId = $isEvenWeek ? $staff2 : $staff1;
                $maxHrs = 14.0;
            }

            // Calculate load_hrs (operating hours)
            mt_srand(crc32($date.$prod->shift_name.'load_hrs'));
            $minsPer300 = mt_rand(70, 80);
            mt_srand();

            // If actuals are not set yet, simulate realistic operator entry (expected ± 2-5% variance)
            if (empty($prod->cft_produced) || (float) $prod->cft_produced <= 0) {
                mt_srand(crc32($date.$prod->shift_name.'demo_operator_actuals'));
                $variance = 1.0 + (mt_rand(-50, 50) / 1000.0);
                $cftProduced = round(($cftExpected * $variance) / 100.0) * 100.0;
                mt_srand();
            } else {
                $cftProduced = (float) $prod->cft_produced;
            }

            $loadHrs = round(($cftProduced / 300.0) * ($minsPer300 / 60.0), 2);
            $loadHrs = min($loadHrs, $maxHrs);

            DB::table('pulse_summary_pith_productions')
                ->where('id', $prod->id)
                ->update([
                    'cft_produced' => $cftProduced,
                    'staff_id' => $staffId,
                    'load_hrs' => $loadHrs,
                    'updated_at' => now(),
                ]);

            $this->info("  Simulated operator entry ({$prod->shift_name}): Staff ID {$staffId} | Expected {$cftExpected} CFT -> Actuals {$cftProduced} CFT | Prod Hours {$loadHrs} hrs");

            $totalCft += $cftProduced;
        }

        return $totalCft;
    }

    /**
     * Distribute shift actuals into 8 drying yard locations based on 19-day staggered cycle.
     */
    private function processPithYardInventory(string $date, int $teamId): void
    {
        $locationIds = DB::table('pulse_pith_locations')
            ->where('team_id', $teamId)
            ->orderBy('id')
            ->pluck('id')
            ->toArray();

        if (empty($locationIds)) {
            return;
        }

        // Delete existing unarchived inventory records for this date to avoid duplicate seeding
        DB::table('pulse_pith_inventory')
            ->whereIn('pith_location_id', $locationIds)
            ->where('date', $date)
            ->delete();

        DB::table('pulse_pith_stock_depletions')
            ->whereIn('pith_location_id', $locationIds)
            ->where('depletion_date', $date)
            ->delete();

        $startDate = Carbon::parse('2026-01-21');
        $currentDate = Carbon::parse($date);
        $dayIndex = (int) $startDate->diffInDays($currentDate);
        if ($currentDate->lt($startDate)) {
            $dayIndex = 0;
        }

        $inwardLocs = [];
        $locCycleDays = [];

        foreach ($locationIds as $index => $locId) {
            $offset = $index * 3;
            $cycleDay = ($dayIndex + $offset) % 19;
            $locCycleDays[$locId] = $cycleDay;

            if ($cycleDay >= 0 && $cycleDay <= 6) {
                $inwardLocs[] = $locId;
            }
        }

        $productions = DB::table('pulse_summary_pith_productions')
            ->where('team_id', $teamId)
            ->where('shift_date', $date)
            ->get();

        foreach ($productions as $prod) {
            $cftProduced = (float) ($prod->cft_produced ?? 0);
            if ($cftProduced > 0 && ! empty($inwardLocs)) {
                $cftPerLocation = round($cftProduced / count($inwardLocs), 1);
                foreach ($inwardLocs as $locId) {
                    DB::table('pulse_pith_inventory')->insert([
                        'pith_production_id' => $prod->id,
                        'pith_location_id' => $locId,
                        'date' => $date,
                        'cft_offloaded' => $cftPerLocation,
                        'taken_cft_calculated' => null,
                        'is_archived' => 0,
                        'created_by' => 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        foreach ($locationIds as $locId) {
            $cycleDay = $locCycleDays[$locId];

            if ($cycleDay >= 14 && $cycleDay <= 18) {
                $totalInward = DB::table('pulse_pith_inventory')
                    ->where('pith_location_id', $locId)
                    ->where('is_archived', 0)
                    ->whereNotNull('cft_offloaded')
                    ->sum('cft_offloaded');

                if ($totalInward > 0) {
                    $cftPerOutwardDay = round($totalInward / 5.0, 1);
                    DB::table('pulse_pith_inventory')->insert([
                        'pith_production_id' => null,
                        'pith_location_id' => $locId,
                        'date' => $date,
                        'cft_offloaded' => null,
                        'taken_cft_calculated' => $cftPerOutwardDay,
                        'is_archived' => 0,
                        'created_by' => 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            if ($cycleDay === 18) {
                $openRecords = DB::table('pulse_pith_inventory')
                    ->where('pith_location_id', $locId)
                    ->where('is_archived', 0)
                    ->get();

                if ($openRecords->isNotEmpty()) {
                    $sumInward = $openRecords->sum('cft_offloaded');
                    $sumOutward = $openRecords->sum('taken_cft_calculated');
                    $firstId = $openRecords->min('id');
                    $lastId = $openRecords->max('id');

                    $depletionId = DB::table('pulse_pith_stock_depletions')->insertGetId([
                        'pith_location_id' => $locId,
                        'depletion_date' => $date,
                        'first_pith_inventory_id' => $firstId,
                        'last_pith_inventory_id' => $lastId,
                        'total_cft_offloaded' => $sumInward,
                        'total_cft_taken_calculated' => $sumOutward,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    DB::table('pulse_pith_inventory')
                        ->whereIn('id', $openRecords->pluck('id'))
                        ->update(['is_archived' => 1, 'pith_stock_depletion_id' => $depletionId]);
                }
            }
        }
    }

    /**
     * Upsert daily husk stock based on CFT produced and incoming husk deliveries.
     */
    private function processHuskDailyStock(string $date, float $totalCftProduced, int $teamId): void
    {
        DB::table('pulse_husks_daily_stock')
            ->where('team_id', $teamId)
            ->where('date', $date)
            ->delete();

        $prev = DB::table('pulse_husks_daily_stock')
            ->where('team_id', $teamId)
            ->where('date', '<', $date)
            ->orderBy('date', 'desc')
            ->first();

        $opening = $prev ? (float) $prev->closing_stock : 50000.0;
        $husksConsumed = round($totalCftProduced * self::PC_HUSKS_PER_CFT, 2);

        mt_srand(crc32($date.'pith_husk_stock_'.$teamId));

        // Delivery frequency: Trucks arrive on ~40% of days (2-3 days a week, NOT every day)
        $hasDelivery = (mt_rand(1, 100) <= 40);
        $husksReceived = 0;
        if ($hasDelivery) {
            $truckCount = mt_rand(1, 2);
            for ($i = 0; $i < $truckCount; $i++) {
                $husksReceived += mt_rand(4500, 6500);
            }
        }

        // Periodic physical audit correction (~5% chance, once every 15-20 days)
        $hasCorrection = (mt_rand(1, 100) <= 5);
        $husksCorrection = 0;
        $correctionComment = null;
        if ($hasCorrection) {
            $husksCorrection = mt_rand(-1500, 1500);
            $comments = [
                'Physical stock audit adjustment',
                'Monthly yard reconciliation correction',
                'Moisture loss & husk breakdown variance',
                'Scale weight calibration adjustment',
            ];
            $correctionComment = $comments[array_rand($comments)];
        }

        mt_srand();

        $closing = max(0.0, round($opening + $husksReceived - $husksConsumed + $husksCorrection, 2));

        DB::table('pulse_husks_daily_stock')->insert([
            'user_id' => 1,
            'team_id' => $teamId,
            'date' => $date,
            'prod_date' => $date,
            'opening_stock' => $opening,
            'husks_received' => $husksReceived ?: null,
            'cft' => $totalCftProduced,
            'closing_stock' => $closing,
            'husks_correction' => $husksCorrection,
            'correction_comment' => $correctionComment,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Simulate staff attendance for demo mode (pulse_attendance).
     */
    private function processAttendance(string $date, int $teamId): void
    {
        DB::table('pulse_attendance')->where('team_id', $teamId)->where('date', $date)->delete();

        $day = Carbon::parse($date);
        $isOffDay = ($teamId === $this->fibreTeamId) ? $day->isTuesday() : $day->isSunday();
        if ($isOffDay) {
            return;
        }

        $staffMembers = DB::table('staffs')->where('team_id', $teamId)->where('is_active', 1)->get();
        if ($staffMembers->isEmpty()) {
            return;
        }

        mt_srand(crc32($date.'attendance'.$teamId));
        $count = min(count($staffMembers), mt_rand(3, count($staffMembers)));
        $selectedStaff = $staffMembers->random($count);

        $rows = [];
        foreach ($selectedStaff as $staff) {
            $duration = mt_rand(7, 9);
            $hourlyRate = (float) ($staff->salary_per_hr ?: 85);
            $salary = round($duration * $hourlyRate, 2);

            $rows[] = [
                'team_id' => $teamId,
                'staff_id' => $staff->id,
                'date' => $date,
                'duration' => $duration,
                'salary' => $salary,
                'task_description' => 'Defibre Plant Operation & Maintenance',
                'created_by' => 1,
                'updated_by' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        mt_srand();

        DB::table('pulse_attendance')->insert($rows);
        $this->info("  Attendance simulated for {$date}: ".count($rows).' staff records.');
    }

    /**
     * Populate blocks_produced and staff_id for Pith Blocks submodule (pulse_summary_pith_blocks).
     */
    private function processPithBlocksShiftActuals(string $date, int $teamId): void
    {
        $blockProductions = DB::table('pulse_summary_pith_blocks')
            ->where('team_id', $teamId)
            ->where('shift_date', $date)
            ->get();

        if ($blockProductions->isEmpty()) {
            return;
        }

        $staffIds = DB::table('staffs')->where('team_id', $teamId)->where('is_active', 1)->pluck('id')->toArray();
        if (count($staffIds) < 2) {
            $staffIds = [123, 124];
        }
        $staff1 = $staffIds[0];
        $staff2 = $staffIds[1] ?? $staffIds[0];

        $day = Carbon::parse($date);
        $weekNumber = (int) $day->format('W');
        $isEvenWeek = ($weekNumber % 2 === 0);

        foreach ($blockProductions as $prod) {
            $blocksExpected = (int) $prod->blocks_expected;
            if ($blocksExpected <= 0) {
                continue;
            }

            $staffId = $isEvenWeek ? $staff1 : $staff2;

            if (empty($prod->blocks_produced) || (int) $prod->blocks_produced <= 0) {
                mt_srand(crc32($date.'pith_blocks_actuals'));
                $variance = 1.0 + (mt_rand(-20, 20) / 1000.0);
                $blocksProduced = (int) round($blocksExpected * $variance);
                mt_srand();
            } else {
                $blocksProduced = (int) $prod->blocks_produced;
            }

            DB::table('pulse_summary_pith_blocks')
                ->where('id', $prod->id)
                ->update([
                    'blocks_produced' => $blocksProduced,
                    'staff_id' => $staffId,
                    'updated_at' => now(),
                ]);

            $this->info("  Pith Blocks simulated entry ({$date}): Staff ID {$staffId} | Expected {$blocksExpected} blocks -> Produced {$blocksProduced} blocks");
        }
    }

    /**
     * Populate bales_produced and staff_id for Fibre Bales submodule (pulse_summary_mini_bales).
     */
    private function processFibreBalesShiftActuals(string $date, int $teamId): void
    {
        $baleProductions = DB::table('pulse_summary_mini_bales')
            ->where('team_id', $teamId)
            ->where('shift_date', $date)
            ->get();

        if ($baleProductions->isEmpty()) {
            return;
        }

        $staffIds = DB::table('staffs')->where('team_id', $teamId)->where('is_active', 1)->pluck('id')->toArray();
        if (count($staffIds) < 2) {
            $staffIds = [123, 124];
        }
        $staff1 = $staffIds[0];
        $staff2 = $staffIds[1] ?? $staffIds[0];

        $day = Carbon::parse($date);
        $weekNumber = (int) $day->format('W');
        $isEvenWeek = ($weekNumber % 2 === 0);

        foreach ($baleProductions as $prod) {
            $balesExpected = (int) ($prod->bales_expected ?? 0);
            if ($balesExpected <= 0) {
                continue;
            }

            $staffId = $isEvenWeek ? $staff1 : $staff2;

            if (empty($prod->bales_produced) || (int) $prod->bales_produced <= 0) {
                mt_srand(crc32($date.'fibre_bales_actuals'));
                $variance = 1.0 + (mt_rand(-20, 20) / 1000.0);
                $balesProduced = (int) round($balesExpected * $variance);
                mt_srand();
            } else {
                $balesProduced = (int) $prod->bales_produced;
            }

            DB::table('pulse_summary_mini_bales')
                ->where('id', $prod->id)
                ->update([
                    'bales_produced' => $balesProduced,
                    'staff_id' => $staffId,
                    'updated_at' => now(),
                ]);

            $this->info("  Fibre Bales simulated entry ({$date}): Staff ID {$staffId} | Expected {$balesExpected} bales -> Produced {$balesProduced} bales");
        }
    }

    /**
     * Simulate daily sales & stock corrections for finished goods (Bales and Blocks),
     * and reconcile the forward stock ledger.
     */
    private function processFinishedGoodsDailyStock(string $date, int $teamId): void
    {
        $now = now();

        // 1. Fibre Bales summary stock
        if (DB::table('pulse_summary_mini_bales')->where('team_id', $teamId)->whereDate('shift_date', $date)->exists()) {
            mt_srand(crc32($date.'bales_sales_'.$teamId));
            $hasSales = (mt_rand(1, 100) <= 57); // 57% of operational days
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
                ->whereDate('shift_date', $date)
                ->update([
                    'sold' => $sold,
                    'correction' => $correction,
                    'correction_comment' => $comment,
                    'updated_at' => $now,
                ]);

            MiniBaleProduction::reconcileInStock($teamId, $date);
            $this->info("  Fibre Bales stock reconciled for date {$date} (Team {$teamId})");
        }

        // 2. Pith Blocks summary stock
        if (DB::table('pulse_summary_pith_blocks')->where('team_id', $teamId)->whereDate('shift_date', $date)->exists()) {
            mt_srand(crc32($date.'blocks_sales_'.$teamId));
            $hasSales = (mt_rand(1, 100) <= 57); // 57% of operational days
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
                ->whereDate('shift_date', $date)
                ->update([
                    'sold' => $sold,
                    'correction' => $correction,
                    'correction_comment' => $comment,
                    'updated_at' => $now,
                ]);

            PithBlocksProduction::reconcileInStock($teamId, $date);
            $this->info("  Pith Blocks stock reconciled for date {$date} (Team {$teamId})");
        }
    }
}
