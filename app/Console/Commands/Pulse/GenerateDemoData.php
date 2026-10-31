<?php

namespace App\Console\Commands\Pulse;

use App\Support\Pulse\CounterSeed;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenerateDemoData extends Command
{
    protected $signature = 'demo:generate-data {--date=} {--start-date=} {--end-date=} {--factory=}';

    protected $description = 'Generate demo data for the demo factories (Fibre, Pith, GrowBags)';

    // Team and device IDs — dynamically resolved from pulse_devices table at runtime
    private $FC_TEAM;

    private $FC_DEV;

    private $PC_TEAM;

    private $PC_DEV;

    private $GC_TEAM;

    private $GC_DEV;

    // GrowBags machines (G1: 1 punch, G2: 2 punches, G3: 4 punches)
    private const GC_MACHINES = [
        ['id' => 1, 'field_id' => 'D31', 'name' => 'G1', 'bags_per_punch' => 1],
        ['id' => 2, 'field_id' => 'D32', 'name' => 'G2', 'bags_per_punch' => 2],
        ['id' => 4, 'field_id' => 'D34', 'name' => 'G3', 'bags_per_punch' => 4],
    ];

    // What one grow bag costs to press. At the demo factory's RS_PER_EB_UNIT of 9.50 that is
    // about a rupee a bag, which is what the floor budgets for.
    private const GC_EB_UNITS_PER_BAG = 0.1;

    private const GC_PUNCHES = [
        'G1' => ['full' => [400, 600], 'partial' => [150, 300]],
        'G2' => ['full' => [300, 500], 'partial' => [120, 250]],
        'G3' => ['full' => [200, 350], 'partial' => [80, 160]],
    ];

    // Festival / public holidays — full day OFF (no production, idle power only)
    // Format: 'm-d' (month-day, applies every year in the demo dataset)
    private const PC_HOLIDAYS = [
        '01-14', // Pongal — Day 1 (Thai Pongal)
        '01-15', // Pongal — Day 2 (Thiruvalluvar Day)
        '01-16', // Pongal — Day 3 (Mattu Pongal)
        '10-20', // Diwali approximate (year 1)
        '11-01', // Diwali / Kali Chaudas alternate
        '11-12', // Diwali alternate (year 2)
    ];

    // Machine event catalogue for pith factory
    // Format: [event_name, min_duration_min, max_duration_min, timeline_state, freq_per_100_shifts]
    private const PC_MACHINE_EVENTS = [
        ['lubrication',      10, 15,  'off',         42],  // ~3× / week per shift
        ['dryer_cleaning',   15, 30,  'off',         28],  // ~2× / week per shift
        ['cyclone_blockage', 10, 25,  'underloaded', 28],  // ~2× / week per shift
        ['conveyor_jam',      5, 20,  'off',         42],  // ~3× / week per shift
        ['inverter_reset',    3,  8,  'off',         14],  // ~1× / week per shift
        ['sensor_calib',      5, 10,  'off',         14],  // ~1× / week per shift
        ['compressor_issue', 10, 30,  'underloaded', 14],  // ~1× / week per shift
        ['belt_replacement', 30, 90,  'off',          6],  // ~2× / month
        ['bearing_replace',  45, 120,  'off',          3],  // ~1× / month
        ['motor_overheat',   20, 45,  'off',          6],  // ~2× / month
        ['elec_maintenance', 30, 60,  'off',          3],  // ~1× / month
    ];

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Resolve both team IDs and device IDs from pulse_devices using the module column.
     * Since 1 team = 1 device = 1 module, module is a unique identifier.
     * A single query fetches everything — no config or env dependency needed.
     */
    private function resolveDeviceIds(): void
    {
        $devices = DB::table('pulse_devices')
            ->whereIn('module', ['fibre', 'pith', 'growbags'])
            ->select('team_id', 'device_id', 'module')
            ->get()
            ->keyBy('module');

        $fibre = $devices->get('fibre') ?? throw new \RuntimeException('No device found in pulse_devices with module=fibre');
        $pith = $devices->get('pith') ?? throw new \RuntimeException('No device found in pulse_devices with module=pith');
        $growbags = $devices->get('growbags') ?? throw new \RuntimeException('No device found in pulse_devices with module=growbags');

        $this->FC_TEAM = $fibre->team_id;
        $this->FC_DEV = $fibre->device_id;

        $this->PC_TEAM = $pith->team_id;
        $this->PC_DEV = $pith->device_id;

        $this->GC_TEAM = $growbags->team_id;
        $this->GC_DEV = $growbags->device_id;
    }

    public function handle(): int
    {
        $this->resolveDeviceIds();
        $startDateOpt = $this->option('start-date');
        $endDateOpt = $this->option('end-date');

        if ($startDateOpt && $endDateOpt) {
            $start = Carbon::parse($startDateOpt);
            $end = Carbon::parse($endDateOpt);

            if ($start->gt($end)) {
                $this->error('❌ Start date cannot be after End date!');

                return self::FAILURE;
            }

            $this->info("Starting bulk demo data generation: {$start->toDateString()} to {$end->toDateString()}");

            $current = $start->copy();
            while ($current->lte($end)) {
                $this->generateForSingleDate($current->toDateString());
                $current->addDay();
            }
            $this->info('Bulk generation finished successfully.');

            return self::SUCCESS;
        }

        $dateOpt = $this->option('date');
        // Shift-aware default date resolution:
        // Between 00:00 AM and 07:59 AM, current time belongs to yesterday's active 8 AM - 8 AM shift day!
        $defaultDate = (Carbon::now()->hour < 8) ? Carbon::yesterday()->toDateString() : Carbon::today()->toDateString();
        $date = $dateOpt ? Carbon::parse($dateOpt)->toDateString() : $defaultDate;

        if (Carbon::parse($date)->isFuture()) {
            $this->error('❌ Cannot generate data for future dates!');
            $this->error("   Requested: {$date} | Today: ".Carbon::today()->toDateString());

            return self::FAILURE;
        }

        $this->generateForSingleDate($date);

        return self::SUCCESS;
    }

    private function generateForSingleDate(string $date): void
    {
        $isActiveShiftDate = ($date === Carbon::today()->toDateString() && Carbon::now()->hour >= 8) ||
                             ($date === Carbon::yesterday()->toDateString() && Carbon::now()->hour < 8);
        $cutoff = $isActiveShiftDate ? Carbon::now() : Carbon::parse($date)->addDay()->setTime(8, 0, 0);
        $day = Carbon::parse($date);
        $this->info("Generating demo data for: {$date} (cutoff: {$cutoff->format('Y-m-d H:i')})");

        $factory = $this->option('factory');

        $ft = $this->dayType($day, $this->FC_TEAM);
        $pt = $this->dayType($day, $this->PC_TEAM);
        $gt = $this->dayType($day, $this->GC_TEAM);

        // ── Fibre Factory ──────────────────────────────────────────────
        if (! $factory || $factory === 'fibre') {
            $this->info("  Fibre={$ft}");
            $this->fibreBales($date, $ft, $cutoff);
        }

        // ── Pith Factory ──────────────────────────────────────────────
        if (! $factory || $factory === 'pith') {
            $this->info("  Pith={$pt}");
            $this->pithDeviceData($date, $pt, $cutoff);
            $this->pithBalesData($date, $pt, $cutoff);
            $this->pithBlocksData($date, $pt, $cutoff);
            $this->waterMeters($date, $pt, $this->PC_DEV, $cutoff);
            $this->waterTanks($date, $pt, $this->PC_DEV, $cutoff);
        }

        // ── GrowBags Factory ──────────────────────────────────────────
        if (! $factory || $factory === 'growbags') {
            $this->info("  GrowBags={$gt}");
            $this->growBagsData($date, $gt, $cutoff);
        }

        $this->info("Done: {$date}");
    }

    // ─────────────────────────────────────────────────────────────────
    // DAY TYPE
    // ─────────────────────────────────────────────────────────────────
    private function dayType(Carbon $day, int $teamId): string
    {
        $isOff = ($teamId === $this->FC_TEAM) ? $day->isTuesday() : $day->isSunday();
        if ($isOff) {
            return 'off';
        }
        $seed = (int) ($day->timestamp / 86400) + $teamId;
        mt_srand($seed);
        $r = mt_rand(1, 100);
        mt_srand();
        if ($r <= 11) {
            return 'downtime';
        }
        if ($r <= 24) {
            return 'partial';
        }

        return 'full';
    }

    /**
     * One flat-counter reading for a shift day the factory sat out.
     *
     * A device that is powered but idle still reports; it simply reports the same counter it
     * reported yesterday. The generator used to write nothing at all on an off day, which
     * made the device look dead for 26+ hours, and {@see CounterSeed} rightly refuses to carry
     * a counter across a silence that long — it cannot tell a closed factory from a device
     * that died while the machine kept running. One idle reading is enough to tell them
     * apart: it keeps the chain of shift days unbroken, so Monday's walk seeds from Sunday
     * and the first punch back is measured rather than guessed at.
     *
     * The counters are copied from the last reading before it, so the idle day's own travel
     * is zero and no summary row is written for it. The unique key on
     * (device, [field,] date, counter) means a repeat run cannot double it up, and by the
     * same token it allows exactly one such row per day — which is all that is wanted.
     *
     * @param  list<string>  $counterColumns  the counters to hold where they stand
     * @param  array<string, string>  $partition  the column => value this counter belongs to
     */
    private function idleHeartbeat(
        string $table,
        int $deviceId,
        string $date,
        string $dateColumn,
        string $timeColumn,
        array $counterColumns,
        Carbon $cutoff,
        array $partition = [],
    ): void {
        // Midday sits inside the shift day for any morning anchor, and the demo factories
        // open at 07:00 and 08:00.
        $at = Carbon::parse($date)->setTime(12, 0, 0);

        if ($at->gt($cutoff)) {
            return;
        }

        $last = DB::table($table)
            ->where('device_id', $deviceId)
            ->where($partition)
            ->where($timeColumn, '<', $at->toDateTimeString())
            ->orderByDesc($timeColumn)
            ->first();

        // Nothing has ever been read from this device, so there is no counter to hold and no
        // chain to keep unbroken.
        if ($last === null) {
            return;
        }

        $row = array_merge($partition, [
            'device_id' => $deviceId,
            $dateColumn => $date,
            $timeColumn => $at->toDateTimeString(),
            'is_duplicate' => 0,
            'created_at' => $at->toDateTimeString(),
            'updated_at' => $at->toDateTimeString(),
        ]);

        foreach ($counterColumns as $column) {
            $row[$column] = $last->{$column};
        }

        DB::table($table)->insertOrIgnore([$row]);
    }

    // ─────────────────────────────────────────────────────────────────
    // FIBRE BALES (Fibre Major Factory - Standalone 2-Shift Setup)
    // ─────────────────────────────────────────────────────────────────
    private function fibreBales(string $date, string $type, Carbon $cutoff): void
    {
        $lastRecordedBaleDt = DB::table('pulse_device_data_fibre_bales')
            ->where('device_id', $this->FC_DEV)
            ->where('bale_date', $date)
            ->max('bale_datetime');

        if ($this->option('date') && $lastRecordedBaleDt) {
            DB::table('pulse_device_data_fibre_bales')->where('device_id', $this->FC_DEV)->where('bale_date', $date)->delete();
            DB::table('pulse_device_data_fibre_mega_bales_weight')->where('device_id', $this->FC_DEV)->where('bale_weight_date', $date)->delete();
            $lastRecordedBaleDt = null;
        }

        $day = Carbon::parse($date);
        $dayOfWeek = $day->dayOfWeek;
        $dayOfMonth = $day->day;

        // Off on Tuesdays, Festival Holidays (Pongal, Diwali), and Shutdown days (10th and 20th)
        if ($dayOfWeek === Carbon::TUESDAY || $this->isPithHoliday($day) || in_array($dayOfMonth, [10, 20], true)) {
            $this->info('  Fibre bales: Tuesday / Holiday / Shutdown day — 0 bales produced');
            $this->idleHeartbeat(
                'pulse_device_data_fibre_bales',
                $this->FC_DEV,
                $date,
                'bale_date',
                'bale_datetime',
                ['bales_counter', 'eb_units_counter'],
                $cutoff,
            );

            return;
        }

        $baleRows = [];
        $weightRows = [];
        $totalProduced = 0;

        $lastBale = DB::table('pulse_device_data_fibre_bales')
            ->where('device_id', $this->FC_DEV)
            ->where('bale_datetime', '<', Carbon::parse($date.' 07:00:00')->toDateTimeString())
            ->orderBy('bale_datetime', 'desc')
            ->first();

        $ctr = $lastBale ? (int) $lastBale->bales_counter : 10000;
        $eb = $lastBale ? (int) $lastBale->eb_units_counter : 1500;

        $isActiveShiftDate = ($date === Carbon::today()->toDateString() && Carbon::now()->hour >= 8) ||
                             ($date === Carbon::yesterday()->toDateString() && Carbon::now()->hour < 8);

        // Saturday Throughput Factor (85% to 92%)
        $satFactor = 1.0;
        if ($day->isSaturday()) {
            mt_srand(crc32($date.'fibre_sat_factor'));
            $satFactor = mt_rand(85, 92) / 100.0;
            mt_srand();
            $this->info("  Fibre Major: Saturday factor {$satFactor} applied");
        }

        $activeShifts = [1, 2];

        foreach ($activeShifts as $shiftNum) {
            if ($shiftNum === 1) {
                // Shift 1: 07:00 AM to 18:00 PM
                $shiftStart = Carbon::parse($date.' 07:00:00');
                $shiftEnd = Carbon::parse($date.' 18:00:00');
                mt_srand(crc32($date.'fibre_shift1_target'));
                $targetBales = (int) round(mt_rand(120, 140) * $satFactor);
            } else {
                // Shift 2: 19:00 PM to 06:45 AM Next Day (produces until 06:45 AM)
                $shiftStart = Carbon::parse($date.' 19:00:00');
                $shiftEnd = Carbon::parse($date.' 06:45:00')->addDay();
                mt_srand(crc32($date.'fibre_shift2_target'));
                $targetBales = (int) round(mt_rand(200, 240) * $satFactor);
            }

            // Organic Breaks & Per-Day Jitter
            if ($shiftNum === 1) {
                $startJitterMins = mt_rand(3, 15);
                // Shift 1 Breaks:
                // 1. Morning Tea Break: ~09:20 AM (09:15 - 09:25 AM, 18-24m dur)
                // 2. Lunch Break: ~01:00 PM to 01:45 PM (01:00 - 01:10 PM, 40-50m dur)
                // 3. Afternoon Tea Break: ~03:15 PM (03:10 - 03:20 PM, 18-24m dur)
                $break1Start = 135 + mt_rand(0, 10);
                $break1Dur = mt_rand(18, 24);
                $break2Start = 355 + mt_rand(0, 10);
                $break2Dur = mt_rand(40, 50);
                $break3Start = 490 + mt_rand(0, 10);
                $break3Dur = mt_rand(18, 24);
                $wrapUpMins = mt_rand(0, 10);
            } else {
                $startJitterMins = mt_rand(5, 20);
                // Shift 2 Breaks:
                // 1. Dinner Break: ~10:00 PM to 10:45 PM (09:55 - 10:05 PM, 40-50m dur)
                // 2. Night Break 1: ~01:10 AM (01:05 - 01:15 AM, 18-24m dur)
                // 3. Night Break 2: ~04:10 AM (04:05 - 04:15 AM, 18-24m dur)
                $break1Start = 175 + mt_rand(0, 10);
                $break1Dur = mt_rand(40, 50);
                $break2Start = 365 + mt_rand(0, 10);
                $break2Dur = mt_rand(18, 24);
                $break3Start = 545 + mt_rand(0, 10);
                $break3Dur = mt_rand(18, 24);
                $wrapUpMins = mt_rand(0, 10); // Produces up to 06:45 AM
            }

            $curTime = $shiftStart->copy()->addMinutes($startJitterMins);
            $shiftEffectiveEnd = $shiftEnd->copy()->subMinutes($wrapUpMins);
            $shiftLimit = $isActiveShiftDate ? $shiftEffectiveEnd->min($cutoff) : $shiftEffectiveEnd;

            $shiftProduced = 0;

            mt_srand(crc32($date."fibre_shift{$shiftNum}_ticks"));

            while ($curTime->lt($shiftLimit) && $shiftProduced < $targetBales) {
                $minsFromStart = $shiftStart->diffInMinutes($curTime);

                // Skip Break 1 (Morning Tea / Dinner)
                if ($minsFromStart >= $break1Start && $minsFromStart < ($break1Start + $break1Dur)) {
                    $curTime->addMinutes(mt_rand(3, 7));

                    continue;
                }

                // Skip Break 2 (Lunch / Night Break 1)
                if ($minsFromStart >= $break2Start && $minsFromStart < ($break2Start + $break2Dur)) {
                    $curTime->addMinutes(mt_rand(3, 7));

                    continue;
                }

                // Skip Break 3 (Afternoon Tea / Night Break 2)
                if ($minsFromStart >= $break3Start && $minsFromStart < ($break3Start + $break3Dur)) {
                    $curTime->addMinutes(mt_rand(3, 7));

                    continue;
                }

                // Cycle time: Shift 1 = 210 to 250s (~3.5 to 4.1 mins); Shift 2 = 140 to 175s (~2.3 to 2.9 mins)
                // Organic per-bale speed variations for organic non-repeating daily patterns
                $baseCycle = ($shiftNum === 1) ? mt_rand(210, 250) : mt_rand(140, 175);
                $cycleSecs = $baseCycle + mt_rand(-15, 25);
                $curTime->addSeconds(max(90, $cycleSecs));

                if ($curTime->gte($shiftLimit)) {
                    break;
                }

                $ctr++;
                $eb += mt_rand(1, 3);

                // Weight Distribution Logic:
                // 5% Underweight (< 120kg), 85% Standard (120-140kg, avg ~134kg), 10% Heavy (> 140kg)
                $wRoll = mt_rand(1, 100);
                if ($wRoll <= 5) {
                    // Underweight: 114.5 to 119.5 kg
                    $baleWeight = round(mt_rand(1145, 1195) / 10.0, 1);
                } elseif ($wRoll <= 90) {
                    // Standard Target Weight: 120.0 to 139.9 kg (avg ~133-135 kg)
                    $baleWeight = round(mt_rand(1200, 1399) / 10.0, 1);
                } else {
                    // Heavy Weight: 140.1 to 148.0 kg
                    $baleWeight = round(mt_rand(1401, 1480) / 10.0, 1);
                }

                $dtStr = $curTime->toDateTimeString();

                if ($lastRecordedBaleDt && $dtStr <= $lastRecordedBaleDt) {
                    $shiftProduced++;

                    continue;
                }

                $baleRows[] = [
                    'device_id' => $this->FC_DEV,
                    'bale_date' => $date,
                    'bale_datetime' => $dtStr,
                    'bales_counter' => $ctr,
                    'is_duplicate' => 0,
                    'eb_units_counter' => $eb,
                    'shift_id' => null,
                    'created_at' => $dtStr,
                    'updated_at' => $dtStr,
                ];

                $weightRows[] = [
                    'device_id' => $this->FC_DEV,
                    'bale_weight_date' => $date,
                    'bale_weight_datetime' => $dtStr,
                    'bale_weight' => $baleWeight,
                    'shift_id' => null,
                    'created_at' => $dtStr,
                    'updated_at' => $dtStr,
                ];

                $shiftProduced++;
            }

            mt_srand();
            $totalProduced += $shiftProduced;
            $this->info("  Fibre Major (Shift {$shiftNum}): {$shiftProduced} bales produced for {$date}");
        }

        foreach (array_chunk($baleRows, 500) as $c) {
            DB::table('pulse_device_data_fibre_bales')->insert($c);
        }
        foreach (array_chunk($weightRows, 500) as $c) {
            DB::table('pulse_device_data_fibre_mega_bales_weight')->insert($c);
        }

        $this->info("  Fibre Major total: {$totalProduced} bales produced for {$date} (Shift 1 + Shift 2)");
    }

    // ─────────────────────────────────────────────────────────────────
    // PITH BALES DATA (Submodule Bales for Team 2)
    // ─────────────────────────────────────────────────────────────────
    private function pithBalesData(string $date, string $type, Carbon $cutoff): void
    {
        $lastRecordedBaleDt = DB::table('pulse_device_data_fibre_bales')
            ->where('device_id', $this->PC_DEV)
            ->where('bale_date', $date)
            ->max('bale_datetime');

        if ($this->option('date') && $lastRecordedBaleDt) {
            DB::table('pulse_device_data_fibre_bales')->where('device_id', $this->PC_DEV)->where('bale_date', $date)->delete();
            $lastRecordedBaleDt = null;
        }

        $day = Carbon::parse($date);
        $dayOfWeek = $day->dayOfWeek;
        $dayOfMonth = $day->day;

        // Off on Sundays and Shutdown days (10th and 20th)
        if ($dayOfWeek === Carbon::SUNDAY || in_array($dayOfMonth, [10, 20], true)) {
            $this->info('  Pith bales: Sunday / Shutdown day — no production');
            $this->idleHeartbeat(
                'pulse_device_data_fibre_bales',
                $this->PC_DEV,
                $date,
                'bale_date',
                'bale_datetime',
                ['bales_counter', 'eb_units_counter'],
                $cutoff,
            );

            return;
        }

        // Rolling No-Baling Day (once every 5 days)
        $dayIndex = (int) Carbon::parse('2026-01-01')->diffInDays($day);
        $isNoBalingDay = ($dayIndex % 5 === 0);

        if ($isNoBalingDay) {
            $this->info('  Pith bales: Rolling No-Baling day — 0 bales produced');
            $this->idleHeartbeat(
                'pulse_device_data_fibre_bales',
                $this->PC_DEV,
                $date,
                'bale_date',
                'bale_datetime',
                ['bales_counter', 'eb_units_counter'],
                $cutoff,
            );

            return;
        }

        // Shift 1 ONLY (08:00 AM to 18:00 PM)
        $shiftStart = Carbon::parse($date.' 08:00:00');
        $shiftEnd = Carbon::parse($date.' 18:00:00');
        $isActiveShiftDate = ($date === Carbon::today()->toDateString() && Carbon::now()->hour >= 8) ||
                             ($date === Carbon::yesterday()->toDateString() && Carbon::now()->hour < 8);
        $endLimit = $isActiveShiftDate ? $shiftEnd->min($cutoff) : $shiftEnd;

        // Target: 100 to 130 bales per shift
        mt_srand(crc32($date.'pith_bales_target'));
        $targetBales = mt_rand(100, 130);

        // Organic Breaks during Shift 1 (~1h 15m total)
        $startJitterMins = mt_rand(5, 20);
        $lunchStartMins = 270 + mt_rand(0, 45); // ~12:30 PM to 01:15 PM
        $lunchDurMins = mt_rand(45, 60);
        $teaStartMins = 435 + mt_rand(0, 30); // ~15:15 PM to 15:45 PM
        $teaDurMins = mt_rand(15, 20);

        $lastBale = DB::table('pulse_device_data_fibre_bales')
            ->where('device_id', $this->PC_DEV)
            ->where('bale_datetime', '<', $shiftStart->toDateTimeString())
            ->orderBy('bale_datetime', 'desc')
            ->first();

        $ctr = $lastBale ? (int) $lastBale->bales_counter : 10000;
        $eb = $lastBale ? (int) $lastBale->eb_units_counter : 1500;

        $curTime = $shiftStart->copy()->addMinutes($startJitterMins);
        $baleRows = [];
        $weightRows = [];
        $producedCount = 0;

        mt_srand(crc32($date.'pith_bales_ticks'));

        while ($curTime->lt($endLimit) && $producedCount < $targetBales) {
            $minsFromStart = $shiftStart->diffInMinutes($curTime);

            // Skip Lunch Break
            if ($minsFromStart >= $lunchStartMins && $minsFromStart < ($lunchStartMins + $lunchDurMins)) {
                $curTime->addMinutes(5);

                continue;
            }

            // Skip Tea Break
            if ($minsFromStart >= $teaStartMins && $minsFromStart < ($teaStartMins + $teaDurMins)) {
                $curTime->addMinutes(5);

                continue;
            }

            // Cycle time: 5 to 7 minutes per bale (300 to 420 seconds)
            $cycleSecs = mt_rand(300, 420);
            $curTime->addSeconds($cycleSecs);

            if ($curTime->gte($endLimit)) {
                break;
            }

            $ctr++;
            $eb += mt_rand(1, 3);

            $dtStr = $curTime->toDateTimeString();

            if ($lastRecordedBaleDt && $dtStr <= $lastRecordedBaleDt) {
                $producedCount++;

                continue;
            }

            $baleRows[] = [
                'device_id' => $this->PC_DEV,
                'bale_date' => $date,
                'bale_datetime' => $dtStr,
                'bales_counter' => $ctr,
                'is_duplicate' => 0,
                'eb_units_counter' => $eb,
                'shift_id' => null,
                'created_at' => $dtStr,
                'updated_at' => $dtStr,
            ];

            $producedCount++;
        }

        mt_srand();

        foreach (array_chunk($baleRows, 500) as $c) {
            DB::table('pulse_device_data_fibre_bales')->insert($c);
        }

        $this->info("  Pith bales: {$producedCount} bales produced for {$date} (Shift 1 only)");
    }

    // ─────────────────────────────────────────────────────────────────
    // PITH BLOCKS DATA
    // ─────────────────────────────────────────────────────────────────
    private function pithBlocksData(string $date, string $type, Carbon $cutoff): void
    {
        $lastRecordedBlockDt = DB::table('pulse_device_data_pith_blocks')
            ->where('device_id', $this->PC_DEV)
            ->where('block_date', $date)
            ->max('block_datetime');

        if ($this->option('date') && $lastRecordedBlockDt) {
            DB::table('pulse_device_data_pith_blocks')->where('device_id', $this->PC_DEV)->where('block_date', $date)->delete();
            $lastRecordedBlockDt = null;
        }

        $day = Carbon::parse($date);

        // Blocks operating policy (shared with defibre exclusivity):
        // 1. Shift 1 runs on No-Baling Days (08:00 to 18:00)
        // 2. Shift 2 runs 2-3 times a week (Tue, Thu, Sat: 18:00 to 08:00 next day)
        // 3. Off on Sundays and Shutdown days (10th and 20th)
        $activeShifts = $this->pithBlocksActiveShifts($day);

        if ($activeShifts === []) {
            if ($day->isSunday() || in_array($day->day, [10, 20], true)) {
                $this->info('  Pith blocks: Sunday / Shutdown day — no production');
            } else {
                $this->info('  Pith blocks: Off day for blocks production');
            }

            $this->idleHeartbeat(
                'pulse_device_data_pith_blocks',
                $this->PC_DEV,
                $date,
                'block_date',
                'block_datetime',
                ['blocks_counter', 'eb_units_counter'],
                $cutoff,
            );

            return;
        }

        $lastBlock = DB::table('pulse_device_data_pith_blocks')
            ->where('device_id', $this->PC_DEV)
            ->where('block_datetime', '<', Carbon::parse($date.' 08:00:00')->toDateTimeString())
            ->orderBy('block_datetime', 'desc')
            ->first();

        $ctr = $lastBlock ? (int) $lastBlock->blocks_counter : 50000;
        $isActiveShiftDate = ($date === Carbon::today()->toDateString() && Carbon::now()->hour >= 8) ||
                             ($date === Carbon::yesterday()->toDateString() && Carbon::now()->hour < 8);

        foreach ($activeShifts as $shiftNum) {
            if ($shiftNum === 1) {
                $shiftStart = Carbon::parse($date.' 08:00:00');
                $shiftEnd = Carbon::parse($date.' 18:00:00');
            } else {
                $shiftStart = Carbon::parse($date.' 18:00:00');
                $shiftEnd = Carbon::parse($date.' 08:00:00')->addDay();
            }

            $endLimit = $isActiveShiftDate ? $shiftEnd->min($cutoff) : $shiftEnd;

            mt_srand(crc32($date."blocks_shift_{$shiftNum}"));
            $targetBlocks = mt_rand(1100, 1300);

            // Organic Breaks (~1h 25m total)
            $startJitterMins = mt_rand(10, 15);
            $mealStartMins = ($shiftNum === 1) ? 270 + mt_rand(0, 30) : 240 + mt_rand(0, 30);
            $mealDurMins = mt_rand(45, 60);
            $teaStartMins = ($shiftNum === 1) ? 435 + mt_rand(0, 30) : 480 + mt_rand(0, 30);
            $teaDurMins = 15;

            $curTime = $shiftStart->copy()->addMinutes($startJitterMins);
            $producedShift = 0;
            $blockRows = [];

            while ($curTime->lt($endLimit) && $producedShift < $targetBlocks) {
                $minsFromStart = $shiftStart->diffInMinutes($curTime);

                // Skip Meal Break
                if ($minsFromStart >= $mealStartMins && $minsFromStart < ($mealStartMins + $mealDurMins)) {
                    $curTime->addMinutes(2);

                    continue;
                }

                // Skip Tea Break
                if ($minsFromStart >= $teaStartMins && $minsFromStart < ($teaStartMins + $teaDurMins)) {
                    $curTime->addMinutes(2);

                    continue;
                }

                // Cycle time: 22 to 27 seconds per block
                $cycleSecs = mt_rand(22, 27);
                $curTime->addSeconds($cycleSecs);

                if ($curTime->gte($endLimit)) {
                    break;
                }

                $ctr++;
                $dtStr = $curTime->toDateTimeString();

                if ($lastRecordedBlockDt && $dtStr <= $lastRecordedBlockDt) {
                    $producedShift++;

                    continue;
                }

                $blockRows[] = [
                    'device_id' => $this->PC_DEV,
                    'block_date' => $date,
                    'block_datetime' => $dtStr,
                    'blocks_counter' => $ctr,
                    'is_duplicate' => 0,
                    'created_at' => $dtStr,
                    'updated_at' => $dtStr,
                ];

                $producedShift++;
            }

            mt_srand();

            foreach (array_chunk($blockRows, 1000) as $c) {
                DB::table('pulse_device_data_pith_blocks')->insert($c);
            }

            $this->info("  Pith blocks (Shift {$shiftNum}): {$producedShift} blocks produced for {$date}");
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // PITH DEVICE DATA (pulse_device_data + daily_summary)
    // ─────────────────────────────────────────────────────────────────
    private function pithDeviceData(string $date, string $type, Carbon $cutoff): void
    {
        $shiftStart = Carbon::parse($date.' 08:00:00');
        $shiftEnd = $shiftStart->copy()->addDay(); // 24h window

        $lastRecordedTickDt = DB::table('pulse_device_data')
            ->where('device_id', $this->PC_DEV)
            ->whereBetween('datetime', [$shiftStart->toDateTimeString(), $shiftEnd->toDateTimeString()])
            ->max('datetime');

        if ($this->option('date') && $lastRecordedTickDt) {
            DB::table('pulse_device_data')->where('device_id', $this->PC_DEV)->whereBetween('datetime', [$shiftStart->toDateTimeString(), $shiftEnd->toDateTimeString()])->delete();
            $lastRecordedTickDt = null;
        }

        // Baseline energy counter (d15) in Wh
        $lastEb = (float) (DB::table('pulse_device_data')
            ->where('device_id', $this->PC_DEV)
            ->where('datetime', '<', $shiftStart->toDateTimeString())
            ->orderBy('datetime', 'desc')
            ->value('d15') ?? 1000000.0);

        $day = Carbon::parse($date);
        $dayOfWeek = $day->dayOfWeek;
        $dayOfMonth = $day->day;

        // Deterministic seeding based on date
        mt_srand(crc32($date.'pith_real_telemetry'));

        // 1. Day Profile Selection
        $profileRoll = mt_rand(1, 100);
        if ($profileRoll <= 15) {
            $dayProfile = 'low_production'; // 15% chance
        } elseif ($profileRoll <= 30) {
            $dayProfile = 'high_efficiency'; // 15% chance
        } else {
            $dayProfile = 'standard';
        }

        // 2. Rolling weekly productivity drop
        $startOfWeekTimestamp = $day->copy()->startOfWeek()->timestamp;
        // Seed based on start of week to keep it consistent for the whole week
        $weekSeed = crc32($startOfWeekTimestamp.'pith_weekly_drop');
        mt_srand($weekSeed);
        $dropDay = mt_rand(1, 6); // Mon-Sat (1-6)
        $dropShift = mt_rand(1, 2); // Shift 1 or Shift 2
        $dropPercent = mt_rand(70, 80); // 70% to 80% reduction

        // Re-seed for daily randomization
        mt_srand(crc32($date.'pith_daily_telemetry'));

        // 3. Define target CFTs for Shift 1 & Shift 2
        // Check festival holidays (Pongal, Diwali) — treated exactly like Sunday
        $isFestivalHoliday = $this->isPithHoliday($day);

        if ($dayOfWeek === Carbon::SUNDAY || $isFestivalHoliday) {
            $targetCft1 = 0.0;
            $targetCft2 = 0.0;
            if ($isFestivalHoliday) {
                $this->info('  Pith: Festival holiday — no production today');
            }
        } else {
            // Shift 1: 1600–2300 CFT (correct range per spec)
            // Shift 2: 15–20% more = naturally 1900–2760 CFT
            $targetCft1 = (float) mt_rand(1600, 2300);
            $targetCft2 = round($targetCft1 * (mt_rand(115, 120) / 100.0), 1);

            // Apply day profile modifiers
            if ($dayProfile === 'low_production') {
                $targetCft1 *= (mt_rand(60, 80) / 100.0);
                $targetCft2 *= (mt_rand(60, 80) / 100.0);
            } elseif ($dayProfile === 'high_efficiency') {
                $targetCft1 *= (mt_rand(110, 120) / 100.0);
                $targetCft2 *= (mt_rand(110, 120) / 100.0);
            }

            // Apply weekly productivity drop
            if ($dayOfWeek === $dropDay) {
                if ($dropShift === 1) {
                    $targetCft1 *= (1.0 - ($dropPercent / 100.0));
                } else {
                    $targetCft2 *= (1.0 - ($dropPercent / 100.0));
                }
            }

            // Saturday: slightly lower production (85–92%) — reduced manpower
            if ($day->isSaturday()) {
                mt_srand(crc32($date.'pith_sat_factor'));
                $satFactor = mt_rand(85, 92) / 100.0;
                mt_srand();
                $targetCft1 = round($targetCft1 * $satFactor, 1);
                $targetCft2 = round($targetCft2 * $satFactor, 1);
                $this->info("  Pith: Saturday factor {$satFactor} applied");
            }

            // Raw material modifier (delayed truck, wet husk, rejected material, etc.)
            $rawMat = $this->getPithRawMaterialModifier($date);
            if ($rawMat['cft_factor'] !== 1.0) {
                $targetCft1 = round($targetCft1 * $rawMat['cft_factor'], 1);
                $targetCft2 = round($targetCft2 * $rawMat['cft_factor'], 1);
                $this->info("  Pith raw material: {$rawMat['event']} (factor {$rawMat['cft_factor']})");
            }

            // Seasonal modifier (rainy season Jun–Sep, summer heat Mar–May)
            $seasonal = $this->getPithSeasonalModifier($day);
            if ($seasonal['cft_base_factor'] !== 1.0) {
                $targetCft1 = round($targetCft1 * $seasonal['cft_base_factor'], 1);
                $targetCft2 = round($targetCft2 * $seasonal['cft_base_factor'], 1);
                $this->info("  Pith seasonal: {$seasonal['reason']} (factor {$seasonal['cft_base_factor']})");
            }

            $targetCft1 = round(max(0.0, $targetCft1), 1);
            $targetCft2 = round(max(0.0, $targetCft2), 1);
        }

        // High-power exclusivity: Defibre + Blocks must not run the same shift.
        // When blocks occupy a shift, force defibre OFF (d7=0), not idle standby.
        $blockShifts = $this->pithBlocksActiveShifts($day);
        if (in_array(1, $blockShifts, true)) {
            $targetCft1 = 0.0;
            $this->info('  Pith: Shift 1 off (blocks running — high-power exclusivity)');
        }
        if (in_array(2, $blockShifts, true)) {
            $targetCft2 = 0.0;
            $this->info('  Pith: Shift 2 off (blocks running — high-power exclusivity)');
        }

        // 4. Build timeline state windows
        $timeline = $this->buildPithStateTimeline($date, $dayProfile, $dayOfWeek, $dayOfMonth, $dropDay, $dropShift, $blockShifts);

        // 5. Calculate Scale Factors per Shift
        $scale1 = $this->calculateShiftScaleFactor($timeline, 0, 36000, $targetCft1, $dayProfile);
        $scale2 = $this->calculateShiftScaleFactor($timeline, 36000, 86400, $targetCft2, $dayProfile);

        // 6. Telemetry Ticks Generation
        $ticks = [];
        $curTime = $shiftStart->copy();

        // ── Midnight-crossing fix ────────────────────────────────────────────────
        // Shift 2 runs 18:00 → 08:00 NEXT DAY, crossing midnight.
        // For PAST dates the outer $cutoff is endOfDay() = 23:59:59, which would
        // silently stop the loop at midnight and lose the entire 00:00→08:00 block.
        // Fix: use $shiftEnd (next-day 08:00) for all completed past dates.
        //      Only apply the $cutoff cap when generating today's partial data.
        $isActiveShiftDate = ($date === Carbon::today()->toDateString() && Carbon::now()->hour >= 8) ||
                             ($date === Carbon::yesterday()->toDateString() && Carbon::now()->hour < 8);
        $endLimit = $isActiveShiftDate ? $shiftEnd->min($cutoff) : $shiftEnd;
        // ────────────────────────────────────────────────────────────────────────

        mt_srand(crc32($date.'pith_ticks'));

        while ($curTime->lt($endLimit)) {
            $ts = $curTime->timestamp;
            $relativeSeconds = $ts - $shiftStart->timestamp;

            // Shift index
            $currentShift = ($relativeSeconds < 36000) ? 1 : 2;
            $scale = ($currentShift === 1) ? $scale1 : $scale2;

            // Get state from timeline
            $state = $timeline[$relativeSeconds] ?? 'off';

            // Jittered interval: 20s ± 10s
            $interval = mt_rand(10, 30);
            $durationHours = $interval / 3600.0;

            // Voltages & Currents base configuration
            if ($state === 'shutdown') {
                $d4 = 0;
                $d5 = 0;
                $d6 = 0;
                $d7 = 0.0;
            } elseif ($state === 'off') {
                $d4 = mt_rand(402, 408);
                $d5 = mt_rand(403, 407);
                $d6 = mt_rand(401, 408);
                $d7 = 0.0;
            } elseif ($state === 'underloaded') {
                $d4 = mt_rand(402, 408);
                $d5 = mt_rand(403, 407);
                $d6 = mt_rand(401, 408);

                // Base current for underloaded: 45A - 85A
                $baseD7 = mt_rand(450, 850) / 10.0;
                $d7 = round($baseD7 * $scale, 1);
                $d7 = max(45.0, min(85.0, $d7)); // Clamp to spec
            } elseif ($state === 'overloaded') {
                $d4 = mt_rand(402, 408);
                $d5 = mt_rand(403, 407);
                $d6 = mt_rand(401, 408);

                // Base current for overloaded: 230A - 250A
                $baseD7 = mt_rand(2300, 2500) / 10.0;
                $d7 = round($baseD7 * $scale, 1);
                $d7 = max(230.0, min(250.0, $d7)); // Clamp to spec
            } else { // efficient
                $d4 = mt_rand(402, 408);
                $d5 = mt_rand(403, 407);
                $d6 = mt_rand(401, 408);

                // Base current for efficient: 145A - 225A
                $baseD7 = mt_rand(1450, 2250) / 10.0;
                if ($dayProfile === 'high_efficiency') {
                    $baseD7 = mt_rand(1950, 2250) / 10.0;
                } elseif ($dayProfile === 'low_production') {
                    $baseD7 = mt_rand(1450, 1750) / 10.0;
                }

                $d7 = round($baseD7 * $scale, 1);
                $d7 = max(145.0, min(225.0, $d7)); // Clamp to spec
            }

            // Power parameters (V * I * sqrt(3) * PF)
            $powerFactor = 0.85;
            $watts = round($d7 * $d4 * 1.73205 * $powerFactor, 1);

            // Phase currents (proportional with average = d7)
            if ($d7 > 0) {
                $d8 = round($d7 + mt_rand(-10, 10) / 10.0, 1);
                $d9 = round($d7 + mt_rand(-10, 10) / 10.0, 1);
                $d10 = round(($d7 * 3.0) - $d8 - $d9, 1);
                $d8 = max(0.0, $d8);
                $d9 = max(0.0, $d9);
                $d10 = max(0.0, $d10);
            } else {
                $d8 = 0.0;
                $d9 = 0.0;
                $d10 = 0.0;
            }

            // Phase watts (proportional with sum = watts)
            if ($watts > 0) {
                $d12 = round($watts / 3.0 + mt_rand(-200, 200), 1);
                $d13 = round($watts / 3.0 + mt_rand(-200, 200), 1);
                $d14 = round($watts - $d12 - $d13, 1);
                $d12 = max(0.0, $d12);
                $d13 = max(0.0, $d13);
                $d14 = max(0.0, $d14);
            } else {
                $d12 = 0.0;
                $d13 = 0.0;
                $d14 = 0.0;
            }

            // Update cumulative Watt-hours
            $deltaEnergyWh = $watts * $durationHours;
            $lastEb += $deltaEnergyWh;

            $dtStr = $curTime->toDateTimeString();
            if (! $lastRecordedTickDt || $dtStr > $lastRecordedTickDt) {
                $ticks[] = [
                    'device_id' => $this->PC_DEV,
                    'datetime' => $dtStr,
                    'd4' => $d4,
                    'd5' => $d5,
                    'd6' => $d6,
                    'd7' => $d7,
                    'd8' => $d8,
                    'd9' => $d9,
                    'd10' => $d10,
                    'd11' => $watts,
                    'd12' => $d12,
                    'd13' => $d13,
                    'd14' => $d14,
                    'd15' => round($lastEb, 2),
                    'created_at' => $dtStr,
                ];
            }

            $curTime->addSeconds($interval);
        }

        mt_srand();

        // Batch insert raw ticks
        foreach (array_chunk($ticks, 1000) as $chunk) {
            DB::table('pulse_device_data')->insert($chunk);
        }

        $this->info('  Pith: Inserted '.count($ticks)." telemetry rows (20s interval jittered) for {$date}");
    }

    /**
     * @param  array<int>  $blockShifts  Shift numbers where pith blocks run (defibre must be off)
     */
    private function buildPithStateTimeline(string $date, string $dayProfile, int $dayOfWeek, int $dayOfMonth, int $dropDay, int $dropShift, array $blockShifts = []): array
    {
        $isShutdownDate = in_array($dayOfMonth, [10, 20], true);

        // Festival holiday (Pongal, Diwali) — full day off, no production, idle power
        if ($this->isPithHoliday(Carbon::parse($date))) {
            return array_fill(0, 86400, 'off');
        }

        // Sunday is fully off (or shutdown if 10th/20th)
        if ($dayOfWeek === Carbon::SUNDAY) {
            $timeline = array_fill(0, 86400, 'off');
            if ($isShutdownDate) {
                for ($i = 3600; $i < 36000; $i++) {
                    $timeline[$i] = 'shutdown';
                }
            }

            return $timeline;
        }

        // Layer 1: Base (efficient)
        $timeline = array_fill(0, 86400, 'efficient');

        mt_srand(crc32($date.'pith_windows'));

        // Layer 2: Startup delays & End-of-shift wrap-up (human behavior jitter)
        // Shift 1: workers start between 08:00 and 08:45 (0 to 45 mins late)
        $s1Delay = mt_rand(0, 2700);
        for ($i = 0; $i < $s1Delay; $i++) {
            $timeline[$i] = 'off';
        }

        // Shift 1 wrap-up: workers stop machine between 17:35 and 18:00 (0 to 25 min early wrap-up)
        $s1WrapUp = mt_rand(0, 1500);
        for ($i = 36000 - $s1WrapUp; $i < 36000; $i++) {
            $timeline[$i] = 'off';
        }

        // Shift 2: handover between shifts — start delay (10 to 35 mins after 18:00)
        $s2Delay = mt_rand(600, 2100);
        for ($i = 36000; $i < 36000 + $s2Delay; $i++) {
            $timeline[$i] = 'off';
        }

        // Layer 3: Generate Underloaded & Overloaded ranges
        // Shift 1 ranges
        $s1UnderTotal = mt_rand(1200, 1800);
        $s1UnderSeq = $this->splitDuration($s1UnderTotal, mt_rand(2, 3));
        $s1UnderRanges = [[600, 5000], [6000, 16000], [21000, 26000]];
        foreach ($s1UnderSeq as $idx => $dur) {
            $r = $s1UnderRanges[$idx % count($s1UnderRanges)];
            $start = mt_rand($r[0], $r[1] - $dur);
            for ($i = $start; $i < $start + $dur; $i++) {
                if ($timeline[$i] === 'efficient') {
                    $timeline[$i] = 'underloaded';
                }
            }
        }

        $s1OverTotal = mt_rand(900, 1200);
        $s1OverSeq = $this->splitDuration($s1OverTotal, mt_rand(2, 3));
        $s1OverRanges = [[2000, 5200], [7000, 15000], [22000, 25500]];
        foreach ($s1OverSeq as $idx => $dur) {
            $r = $s1OverRanges[$idx % count($s1OverRanges)];
            $start = mt_rand($r[0], $r[1] - $dur);
            for ($i = $start; $i < $start + $dur; $i++) {
                if ($timeline[$i] === 'efficient') {
                    $timeline[$i] = 'overloaded';
                }
            }
        }

        // Shift 2 ranges
        $s2UnderTotal = mt_rand(1200, 1800);
        $s2UnderSeq = $this->splitDuration($s2UnderTotal, mt_rand(2, 3));
        $s2UnderRanges = [[37000, 49000], [54000, 60000], [69000, 78000]];
        foreach ($s2UnderSeq as $idx => $dur) {
            $r = $s2UnderRanges[$idx % count($s2UnderRanges)];
            $start = mt_rand($r[0], $r[1] - $dur);
            for ($i = $start; $i < $start + $dur; $i++) {
                if ($timeline[$i] === 'efficient') {
                    $timeline[$i] = 'underloaded';
                }
            }
        }

        $s2OverTotal = mt_rand(900, 1200);
        $s2OverSeq = $this->splitDuration($s2OverTotal, mt_rand(2, 3));
        $s2OverRanges = [[38000, 48000], [55000, 59000], [70000, 77000]];
        foreach ($s2OverSeq as $idx => $dur) {
            $r = $s2OverRanges[$idx % count($s2OverRanges)];
            $start = mt_rand($r[0], $r[1] - $dur);
            for ($i = $start; $i < $start + $dur; $i++) {
                if ($timeline[$i] === 'efficient') {
                    $timeline[$i] = 'overloaded';
                }
            }
        }

        // Apply Productivity Drop (convert 80% of efficient to off/underloaded)
        $isDropActive = ($dayOfWeek === $dropDay);
        if ($isDropActive) {
            $dropShiftRange = ($dropShift === 1) ? [0, 36000] : [36000, 86400];
            for ($i = $dropShiftRange[0]; $i < $dropShiftRange[1]; $i++) {
                if ($timeline[$i] === 'efficient') {
                    $roll = mt_rand(1, 100);
                    if ($roll <= 45) {
                        $timeline[$i] = 'underloaded';
                    } elseif ($roll <= 80) {
                        $timeline[$i] = 'off';
                    }
                }
            }
        }

        // Layer 4: Breaks (Lunch, Tea, Dinner, Fixed Off)
        // Shift 1 Breaks
        $s1LunchStart = mt_rand(17100, 18900); // starts between 12:45 and 13:15
        $s1LunchEnd = $s1LunchStart + 2700;    // 45 mins
        $s1TeaStart = mt_rand(27000, 27300);   // starts between 15:30 and 15:35
        $s1TeaEnd = $s1TeaStart + 900;         // 15 mins
        $s1RandBreakStart = mt_rand(5400, 11700); // 15 mins random break in morning session
        $s1RandBreakEnd = $s1RandBreakStart + 900;

        for ($i = $s1LunchStart; $i < $s1LunchEnd; $i++) {
            $timeline[$i] = 'off';
        }
        for ($i = $s1TeaStart; $i < $s1TeaEnd; $i++) {
            $timeline[$i] = 'off';
        }
        for ($i = $s1RandBreakStart; $i < $s1RandBreakEnd; $i++) {
            $timeline[$i] = 'off';
        }

        // Shift 2 Breaks & Night Stop
        $s2DinnerStart = mt_rand(50400, 51300); // starts between 22:00 and 22:15
        $s2DinnerEnd = $s2DinnerStart + 2700;   // 45 mins
        $s2RandBreakStart = mt_rand(61200, 67500); // 15m break between 01:00 AM and 03:00 AM next day
        $s2RandBreakEnd = $s2RandBreakStart + 900;

        // Shift 2 night stop: varies by day between 05:00 AM and 06:45 AM next day (75600s to 81900s)
        $s2FixedOffStart = mt_rand(75600, 81900);
        $s2FixedOffEnd = 86400; // 08:00 AM next day

        for ($i = $s2DinnerStart; $i < $s2DinnerEnd; $i++) {
            $timeline[$i] = 'off';
        }
        for ($i = $s2RandBreakStart; $i < $s2RandBreakEnd; $i++) {
            $timeline[$i] = 'off';
        }
        for ($i = $s2FixedOffStart; $i < $s2FixedOffEnd; $i++) {
            $timeline[$i] = 'off';
        }

        // Layer 5: Machine Events — random mechanical/electrical events per shift
        // These override production but respect existing break windows for 'underloaded' state
        mt_srand(crc32($date.'pith_machine_events'));
        $machineEvents = $this->getPithMachineEvents($date, $dayOfWeek);
        foreach ($machineEvents as $event) {
            $durSec = $event['dur']; // already in seconds
            if ($event['shift'] === 1) {
                // Place within Shift 1 active production window (after startup delay, before end)
                $wStart = max($s1Delay + 900, 1800);    // at least 15 min into production
                $wEnd = max($wStart + $durSec + 1, 33000 - $durSec); // leave 50 min buffer
            } else {
                // Place within Shift 2 active window (after S2 startup, before fixed-off)
                $wStart = 36000 + $s2Delay + 900;       // at least 15 min into S2 production
                $wEnd = max($wStart + $durSec + 1, 76200 - $durSec); // leave 50 min buffer
            }
            if ($wEnd <= $wStart) {
                continue;
            }
            $evStart = mt_rand($wStart, $wEnd);
            $evEnd = min(86399, $evStart + $durSec);
            for ($i = $evStart; $i < $evEnd; $i++) {
                // 'off' events override everything (machine stopped); 'underloaded' only overrides efficient
                if ($event['state'] === 'off') {
                    $timeline[$i] = 'off';
                } elseif ($timeline[$i] === 'efficient') {
                    $timeline[$i] = $event['state'];
                }
            }
        }

        // Layer 6: Per-shift independent power cuts (~3% chance each shift)
        // Randomly applied independently — a cut on S1 does NOT imply cut on S2
        mt_srand(crc32($date.'pith_powercut'));
        if (mt_rand(1, 100) <= 3) {
            // Power cut during Shift 1 (30–90 min somewhere in active window)
            $cutDur = mt_rand(1800, 5400);
            $cutStart = mt_rand($s1Delay + 1800, max($s1Delay + 1801, 36000 - $cutDur - 1800));
            $cutEnd = min(36000, $cutStart + $cutDur);
            for ($i = $cutStart; $i < $cutEnd; $i++) {
                $timeline[$i] = 'off';
            }
            $this->info('  Pith: Shift 1 power cut simulated ('.round($cutDur / 60).' min)');
        }
        if (mt_rand(1, 100) <= 3) {
            // Power cut during Shift 2 (30–90 min somewhere in active window)
            $cutDur = mt_rand(1800, 5400);
            $cutStart = mt_rand(36000 + $s2Delay + 1800, max(36000 + $s2Delay + 1801, 79200 - $cutDur - 1800));
            $cutEnd = min(79200, $cutStart + $cutDur);
            for ($i = $cutStart; $i < $cutEnd; $i++) {
                $timeline[$i] = 'off';
            }
            $this->info('  Pith: Shift 2 power cut simulated ('.round($cutDur / 60).' min)');
        }
        mt_srand();

        // Layer 5: Shutdown Date overrides
        if ($isShutdownDate) {
            // Shutdown is from 9 AM to 6 PM (3600s to 36000s)
            for ($i = 3600; $i < 36000; $i++) {
                $timeline[$i] = 'shutdown';
            }
        }

        // Layer 7: Defibre OFF when blocks occupy that shift (high-power exclusivity)
        if (in_array(1, $blockShifts, true)) {
            for ($i = 0; $i < 36000; $i++) {
                $timeline[$i] = 'off';
            }
        }
        if (in_array(2, $blockShifts, true)) {
            for ($i = 36000; $i < 86400; $i++) {
                $timeline[$i] = 'off';
            }
        }

        mt_srand();

        return $timeline;
    }

    private function calculateShiftScaleFactor(array $timeline, int $start, int $end, float $targetCft, string $dayProfile): float
    {
        if ($targetCft <= 0) {
            return 0.0;
        }

        $targetWh = $targetCft * 318.0; // 0.318 Wh conversion scaling

        // Estimate power draws per state
        $powerMap = [
            'efficient' => 1.73205 * 405.0 * 185.0 * 0.85,
            'underloaded' => 1.73205 * 405.0 * 65.0 * 0.85,
            'overloaded' => 1.73205 * 405.0 * 240.0 * 0.85,
            'off' => 0.0,
            'shutdown' => 0.0,
        ];

        // Shift modifier on efficient current base
        if ($dayProfile === 'high_efficiency') {
            $powerMap['efficient'] = 1.73205 * 405.0 * 210.0 * 0.85;
        } elseif ($dayProfile === 'low_production') {
            $powerMap['efficient'] = 1.73205 * 405.0 * 160.0 * 0.85;
        }

        $expectedEnergyWh = 0.0;
        for ($i = $start; $i < $end; $i++) {
            $state = $timeline[$i] ?? 'off';
            $watts = $powerMap[$state] ?? 0.0;
            $expectedEnergyWh += ($watts / 3600.0);
        }

        return ($expectedEnergyWh > 0) ? ($targetWh / $expectedEnergyWh) : 1.0;
    }

    private function splitDuration(int $total, int $count): array
    {
        $parts = [];
        $sum = 0;
        for ($i = 0; $i < $count; $i++) {
            $parts[$i] = mt_rand(10, 100);
            $sum += $parts[$i];
        }
        $res = [];
        $distributed = 0;
        for ($i = 0; $i < $count - 1; $i++) {
            $val = (int) round(($parts[$i] / $sum) * $total);
            $res[] = $val;
            $distributed += $val;
        }
        $res[] = $total - $distributed;

        return $res;
    }

    // ─────────────────────────────────────────────────────────────────
    // GROWBAGS DEVICE DATA
    private function growBagsData(string $date, string $type, Carbon $cutoff): void
    {
        if ($type === 'off' || $type === 'downtime') {
            foreach (self::GC_MACHINES as $m) {
                $this->idleHeartbeat(
                    'pulse_device_data_growbags',
                    $this->GC_DEV,
                    $date,
                    'growbag_date',
                    'growbag_datetime',
                    ['growbags_counter', 'eb_units_counter'],
                    $cutoff,
                    ['field_id' => $m['field_id']],
                );
            }

            return;
        }
        $base = Carbon::parse($date);

        $activeShifts = ($type === 'partial') ? [1] : [1, 2];

        $isActiveShiftDate = ($date === Carbon::today()->toDateString() && Carbon::now()->hour >= 8) ||
                             ($date === Carbon::yesterday()->toDateString() && Carbon::now()->hour < 8);

        $lastRecordedGrowbagDt = DB::table('pulse_device_data_growbags')
            ->where('device_id', $this->GC_DEV)
            ->where('growbag_date', $date)
            ->max('growbag_datetime');

        // Delete existing data for this date ONLY if custom date option is explicitly specified
        if ($this->option('date') && $lastRecordedGrowbagDt) {
            DB::table('pulse_device_data_growbags')
                ->where('device_id', $this->GC_DEV)
                ->where('growbag_date', $date)
                ->delete();
            $lastRecordedGrowbagDt = null;
        }

        // Single Main Factory EB Panel baseline counter (1 Growbag Factory = 1 Main EB Counter)
        $factoryEbQuery = DB::table('pulse_device_data_growbags')->where('device_id', $this->GC_DEV);
        if ($lastRecordedGrowbagDt) {
            $factoryEbQuery->where('growbag_datetime', '<=', $lastRecordedGrowbagDt);
        } else {
            $factoryEbQuery->where('growbag_datetime', '<', $date.' 07:00:00');
        }
        $lastFactoryEbRecord = $factoryEbQuery->orderBy('growbag_datetime', 'desc')->first();

        $factoryEb = $lastFactoryEbRecord ? (float) $lastFactoryEbRecord->eb_units_counter : 1000.0;

        $allMachineRows = [];
        $machineCounts = [];

        foreach (self::GC_MACHINES as $m) {
            $mn = $m['name'];
            [$lo, $hi] = self::GC_PUNCHES[$mn][$type === 'partial' ? 'partial' : 'full'];

            // Each machine keeps its own punch counter: the device reports one counter per
            // field_id, climbing by one for every punch that machine makes.
            $lastMachineQuery = DB::table('pulse_device_data_growbags')
                ->where('device_id', $this->GC_DEV)
                ->where('field_id', $m['field_id']);
            if ($lastRecordedGrowbagDt) {
                $lastMachineQuery->where('growbag_datetime', '<=', $lastRecordedGrowbagDt);
            } else {
                $lastMachineQuery->where('growbag_datetime', '<', $date.' 07:00:00');
            }
            $last = $lastMachineQuery->orderBy('growbag_datetime', 'desc')->first();

            // Far enough apart that no two machines' counters can ever meet: the table's
            // unique key is (device, date, counter), and a collision would be dropped by the
            // insertOrIgnore below, silently costing that machine a punch.
            $defaultCtrs = ['G1' => 1000000, 'G2' => 2000000, 'G3' => 3000000];
            $ctr = $last ? (int) $last->growbags_counter : ($defaultCtrs[$mn] ?? 10000);

            $machineTicks = 0;

            foreach ($activeShifts as $shiftNum) {
                $target = ($shiftNum === 1) ? rand((int) ($lo * 0.5), (int) ($hi * 0.55)) : rand((int) ($lo * 0.45), (int) ($hi * 0.5));
                if ($shiftNum === 1) {
                    $ws = $base->copy()->setTime(7, rand(0, 30), 0);
                    $we = $base->copy()->setTime(18, rand(45, 59), 0);
                } else {
                    $ws = $base->copy()->setTime(20, rand(0, 30), 0);
                    $we = $base->copy()->addDay()->setTime(5, rand(45, 55), 0);
                }
                if ($we->lte($ws)) {
                    continue;
                }
                if ($isActiveShiftDate) {
                    $we = $we->min($cutoff);
                }
                if ($we->lte($ws)) {
                    continue;
                }

                $intv = max(1, (int) ($ws->diffInMinutes($we) / max(1, $target)));
                $tick = $ws->copy();
                $n = 0;
                while ($tick->lt($we) && $n < $target) {
                    $dtStr = $tick->toDateTimeString();

                    // If incremental hourly cron run, skip ticks already inserted previously
                    if ($lastRecordedGrowbagDt && $dtStr <= $lastRecordedGrowbagDt) {
                        $tick->addMinutes(max(1, $intv + rand(-1, 1)));
                        $n++;

                        continue;
                    }

                    $ctr++;

                    $allMachineRows[] = [
                        'device_id' => $this->GC_DEV,
                        'field_id' => $m['field_id'],
                        'growbag_date' => $date,
                        'growbag_datetime' => $dtStr,
                        'growbags_counter' => $ctr,
                        'is_duplicate' => 0,
                        'eb_units_counter' => 0.0, // populated from single factory EB stream below
                        'shift_id' => null,
                        'created_at' => $dtStr,
                        'updated_at' => $dtStr,
                        '_mn' => $mn,
                        '_bags' => $m['bags_per_punch'],
                    ];
                    $n++;
                    $tick->addMinutes(max(1, $intv + rand(-1, 1)));
                }
                $machineTicks += $n;
            }
            $machineCounts[$mn] = $machineTicks;
        }

        if (empty($allMachineRows)) {
            return;
        }

        // Sort all machine ticks chronologically to simulate time-series progression
        usort($allMachineRows, function ($a, $b) {
            return strcmp($a['growbag_datetime'], $b['growbag_datetime']);
        });

        // The punch counters belong to the machines; the EB panel belongs to the factory, so
        // it is carried across every machine's ticks in time order. What a punch draws is what
        // the bags it made cost to press, so a bag works out at self::GC_EB_UNITS_PER_BAG give
        // or take the jitter — the figure the growbag factory is costed at.
        foreach ($allMachineRows as &$row) {
            $factoryEb += self::GC_EB_UNITS_PER_BAG * $row['_bags'] * (rand(85, 115) / 100.0);

            $row['eb_units_counter'] = round($factoryEb, 2);
            unset($row['_mn'], $row['_bags']);
        }
        unset($row);

        // Batch insert chronologically formatted rows
        foreach (array_chunk($allMachineRows, 500) as $chunk) {
            DB::table('pulse_device_data_growbags')->insertOrIgnore($chunk);
        }

        foreach ($machineCounts as $mn => $count) {
            $this->info("  GrowBags {$mn}: {$count} ticks generated across shift(s)");
        }
        $this->info('  GrowBags Factory Main EB Counter end value: '.round($factoryEb, 2));
    }

    // ─────────────────────────────────────────────────────────────────
    // WATER METERS
    // ─────────────────────────────────────────────────────────────────
    private function waterMeters(string $date, string $type, int $dev, Carbon $cutoff): void
    {
        $hasMeters = DB::table('pulse_master_water_meters')->where('device_id', $dev)->exists();
        if (! $hasMeters) {
            return;
        }

        $lastRecordedMeterDt = DB::table('pulse_device_data_water_meters')
            ->where('device_id', $dev)
            ->whereIn('field_id', ['d16', 'd27'])
            ->where('meter_date', $date)
            ->max('meter_datetime');

        if ($this->option('date') && $lastRecordedMeterDt) {
            DB::table('pulse_device_data_water_meters')
                ->where('device_id', $dev)
                ->whereIn('field_id', ['d16', 'd27'])
                ->where('meter_date', $date)
                ->delete();
            $lastRecordedMeterDt = null;
        }

        // Hourly fluctuation weights spanning 24 hours (00:00 to 23:00)
        $hourlyFactors = [
            '00:00' => 0.3, '01:00' => 0.2, '02:00' => 0.2, '03:00' => 0.3,
            '04:00' => 0.5, '05:00' => 0.7, '06:00' => 1.0, '07:00' => 1.2,
            '08:00' => 1.4, '09:00' => 1.5, '10:00' => 1.6, '11:00' => 1.5,
            '12:00' => 1.0, '13:00' => 0.6, '14:00' => 1.3, '15:00' => 1.5,
            '16:00' => 1.4, '17:00' => 1.2, '18:00' => 1.0, '19:00' => 0.8,
            '20:00' => 0.6, '21:00' => 0.5, '22:00' => 0.4, '23:00' => 0.3,
        ];

        // Real Factory Dataset Behavior Rules (Inferred from device data watermeter.csv):
        // d27 (Main Line): High-rate burst pumping (35-90 min sessions @ 66-85 L/min flow with 15-45 min idle pauses)
        // d16 (Husk Line): Long continuous low-rate soaking (150-240 min sessions @ 12-15 L/min flow)

        // Calibrated Production Day Targets:
        // Weekly Volume Budget: 80,000 L to 105,000 L total per week (across all days combined)
        // High Peak Day (15% chance): 19,000 to 20,000 Litres max
        // Normal Day (65% chance): 11,000 to 16,000 Litres jitter
        // Low Day / Maintenance (20% chance): 4,000 to 8,000 Litres
        mt_srand(crc32($date.'water_day_profile_'.$dev));
        $dayProfileRoll = mt_rand(1, 100);

        if ($dayProfileRoll <= 15) {
            // Peak Day (max 20k L)
            $targetTotalDailyLitres = (float) mt_rand(19000, 20000);
        } elseif ($dayProfileRoll <= 80) {
            // Normal Working Day (11k - 16k L jitter)
            $targetTotalDailyLitres = (float) mt_rand(11000, 16000);
        } else {
            // Low Day / Maintenance (4k - 8k L)
            $targetTotalDailyLitres = (float) mt_rand(4000, 8000);
        }

        // Randomize overnight operation (~30% chance of overnight Shift 2)
        $hasOvernightShift = (mt_rand(1, 100) <= 30);
        mt_srand();

        // Real CSV Field Split: d27 gets ~72% of volume, d16 gets ~28% of volume
        $dailyDistribution = [
            'd27' => round($targetTotalDailyLitres * 0.72),
            'd16' => round($targetTotalDailyLitres * 0.28),
        ];

        foreach (['d16', 'd27'] as $fid) {
            // Find last counter value prior to target date for seamless continuity
            $last = DB::table('pulse_device_data_water_meters')
                ->where('device_id', $dev)
                ->where('field_id', $fid)
                ->where('meter_date', '<', $date)
                ->orderBy('meter_datetime', 'desc')
                ->value('litres_counter');

            if ($last === null) {
                $last = DB::table('pulse_device_data_water_meters')
                    ->where('device_id', $dev)
                    ->where('field_id', $fid)
                    ->orderBy('meter_datetime', 'desc')
                    ->value('litres_counter') ?? ($fid === 'd16' ? 530000 : 20000);
            }

            $dailyTarget = $dailyDistribution[$fid];

            mt_srand(crc32($date.$fid.$dev));
            $rows = [];

            $dayStart = Carbon::parse($date)->setTime(0, 0, 0);
            $dayEnd = Carbon::parse($date)->setTime(23, 59, 59);

            if (! $hasOvernightShift) {
                $curTime = Carbon::parse($date)->setTime(6, 0, 0)->addMinutes(mt_rand(0, 15));
            } else {
                $curTime = $dayStart->copy()->addMinutes(mt_rand(5, 20));
            }

            if ($fid === 'd27') {
                // ── d27: MAIN FACTORY LINE (Thin Horizontal Strip Lines @ 5-15 min intervals) ──
                while ($curTime->lte($dayEnd) && $curTime->lte($cutoff)) {
                    $timeStr = $curTime->format('H:i');

                    // Shift Handover & Meal Breaks (Wide gaps)
                    if (($timeStr >= '05:30' && $timeStr < '06:00') ||
                        ($timeStr >= '12:30' && $timeStr < '13:30') ||
                        ($timeStr >= '17:30' && $timeStr < '18:00') ||
                        ($timeStr >= '20:30' && $timeStr < '21:15')) {
                        $curTime->addMinutes(mt_rand(25, 45));

                        continue;
                    }

                    // Active flow burst: 15 to 45 minutes
                    $burstDurationMins = mt_rand(15, 45);
                    $burstEnd = $curTime->copy()->addMinutes($burstDurationMins)->min($dayEnd)->min($cutoff);

                    while ($curTime->lte($burstEnd)) {
                        $stepLitres = (int) round(mt_rand(80, 180));
                        $stepLitres = max(10, (int) (round($stepLitres / 10.0) * 10));
                        $last += $stepLitres;

                        $dtStr = $curTime->toDateTimeString();

                        if ($lastRecordedMeterDt && $dtStr <= $lastRecordedMeterDt) {
                            $curTime->addMinutes(mt_rand(6, 14));

                            continue;
                        }

                        $createdDtStr = $curTime->copy()->addSeconds(mt_rand(3, 5))->toDateTimeString();

                        $rows[] = [
                            'device_id' => $dev,
                            'field_id' => 'd27',
                            'meter_date' => $date,
                            'meter_datetime' => $dtStr,
                            'litres_counter' => $last,
                            'is_duplicate' => 0,
                            'created_at' => $createdDtStr,
                            'updated_at' => $createdDtStr,
                        ];

                        // Pulse interval: 6 to 14 minutes (creates distinct thin line strips!)
                        $curTime->addMinutes(mt_rand(6, 14));
                    }

                    // Inter-burst pause (15 to 40 mins gap)
                    $idlePauseMins = mt_rand(15, 40);
                    $curTime->addMinutes($idlePauseMins);
                }
            } else {
                // ── d16: HUSK PROCESSING LINE ────────────────────────────────────────
                while ($curTime->lte($dayEnd) && $curTime->lte($cutoff)) {
                    $runDurationMins = mt_rand(40, 90);
                    $runEnd = $curTime->copy()->addMinutes($runDurationMins)->min($dayEnd)->min($cutoff);

                    while ($curTime->lte($runEnd)) {
                        $stepLitres = mt_rand(20, 60);
                        $last += $stepLitres;

                        $dtStr = $curTime->toDateTimeString();

                        if ($lastRecordedMeterDt && $dtStr <= $lastRecordedMeterDt) {
                            $curTime->addMinutes(mt_rand(8, 18));

                            continue;
                        }

                        $createdDtStr = $curTime->copy()->addSeconds(mt_rand(3, 5))->toDateTimeString();

                        $rows[] = [
                            'device_id' => $dev,
                            'field_id' => 'd16',
                            'meter_date' => $date,
                            'meter_datetime' => $dtStr,
                            'litres_counter' => $last,
                            'is_duplicate' => 0,
                            'created_at' => $createdDtStr,
                            'updated_at' => $createdDtStr,
                        ];

                        // Pulse interval: 8 to 18 minutes (thin lines)
                        $curTime->addMinutes(mt_rand(8, 18));
                    }

                    $idlePauseMins = mt_rand(15, 35);
                    $curTime->addMinutes($idlePauseMins);
                }
            }

            mt_srand();

            foreach (array_chunk($rows, 1000) as $chunk) {
                DB::table('pulse_device_data_water_meters')->insertOrIgnore($chunk);
            }
        }
        $this->info("  Water meters (dev {$dev}): inserted telemetry for {$date}");
    }

    // ─────────────────────────────────────────────────────────────────
    // WATER TANKS
    // ─────────────────────────────────────────────────────────────────
    private function waterTanks(string $date, string $type, int $dev, Carbon $cutoff): void
    {
        $hasTanks = DB::table('pulse_master_water_tanks')->where('device_id', $dev)->exists();
        if (! $hasTanks) {
            return;
        }

        if ($this->option('date')) {
            DB::table('pulse_device_data_water_tank_levels')->where('device_id', $dev)->whereIn('field_id', ['d29', 'd30'])->where('level_date', $date)->delete();
        }
        $readingTime = Carbon::parse($date)->setTime(8, 30, 0);
        if ($readingTime->gt($cutoff)) {
            $this->info("  Water tanks (dev {$dev}): skipped");

            return;
        }

        // Define tank height configurations in cm (d29 = 487.68 cm, d30 = 192.02 cm)
        $tanks = [
            'd29' => ['height' => 487.68],
            'd30' => ['height' => 192.02],
        ];

        $rows = [];
        mt_srand(crc32($date.'water_tanks_'.$dev));

        foreach ($tanks as $fid => $cfg) {
            $totalHeight = $cfg['height'];

            // Target fill percentage varying organically between 15% and 88% based on day type
            $targetFillPercent = match ($type) {
                'off', 'downtime' => mt_rand(15, 35) / 100.0,  // Low level on off/downtime days (15% - 35%)
                'partial' => mt_rand(40, 65) / 100.0,  // Mid level on partial days (40% - 65%)
                default => mt_rand(55, 88) / 100.0,  // High level on full production days (55% - 88%)
            };

            // Available height is the empty air space from sensor down to water surface (in cm)
            $availableHeight = round($totalHeight * (1.0 - $targetFillPercent), 2);

            $rows[] = [
                'device_id' => $dev,
                'field_id' => $fid,
                'level_date' => $date,
                'level_datetime' => $readingTime->toDateTimeString(),
                'available_height' => $availableHeight,
                'created_at' => $readingTime->toDateTimeString(),
                'updated_at' => $readingTime->toDateTimeString(),
            ];
        }

        mt_srand();

        DB::table('pulse_device_data_water_tank_levels')->insertOrIgnore($rows);
        $this->info("  Water tanks (dev {$dev}): 2 readings");
    }

    // ─────────────────────────────────────────────────────────────────
    // PITH FACTORY HELPER METHODS
    // ─────────────────────────────────────────────────────────────────

    /**
     * Check if a given day is a festival holiday (Pongal, Diwali).
     * These days are treated exactly like Sundays — full day OFF.
     */
    private function isPithHoliday(Carbon $day): bool
    {
        return in_array($day->format('m-d'), self::PC_HOLIDAYS, true);
    }

    /**
     * Shift numbers where pith blocks run for the given day.
     * Shared by pithBlocksData and pithDeviceData so Defibre↔Blocks exclusivity cannot drift.
     *
     * Policy:
     * - Off: Sundays and shutdown days (10th / 20th)
     * - Shift 1: No-Baling days (every 5th day from 2026-01-01)
     * - Shift 2: Tue / Thu / Sat nights
     *
     * @return array<int>
     */
    private function pithBlocksActiveShifts(Carbon $day): array
    {
        if ($day->isSunday() || in_array($day->day, [10, 20], true)) {
            return [];
        }

        $dayIndex = (int) Carbon::parse('2026-01-01')->diffInDays($day);
        $isNoBalingDay = ($dayIndex % 5 === 0);
        $isShift2Day = in_array($day->dayOfWeek, [Carbon::TUESDAY, Carbon::THURSDAY, Carbon::SATURDAY], true);

        if (! $isNoBalingDay && ! $isShift2Day) {
            return [];
        }

        $shifts = [];
        if ($isNoBalingDay) {
            $shifts[] = 1;
        }
        if ($isShift2Day) {
            $shifts[] = 2;
        }

        return $shifts;
    }

    /**
     * Determine machine events that occur during this day's shifts.
     * Each event has a cause, duration (seconds), state, and assigned shift.
     * Events are deterministic per date (same result on re-run).
     */
    private function getPithMachineEvents(string $date, int $dayOfWeek): array
    {
        if ($dayOfWeek === Carbon::SUNDAY) {
            return [];
        }

        mt_srand(crc32($date.'pith_mach_ev'));
        $events = [];

        foreach (self::PC_MACHINE_EVENTS as $spec) {
            [$name, $minDur, $maxDur, $state, $freqPer100] = $spec;
            // Roll against per-100-shifts frequency for each event type
            if (mt_rand(1, 100) <= $freqPer100) {
                $events[] = [
                    'cause' => $name,
                    'dur' => mt_rand($minDur, $maxDur) * 60,  // convert to seconds
                    'state' => $state,
                    'shift' => mt_rand(1, 2),                    // which shift this occurs in
                ];
            }
        }

        mt_srand();

        return $events;
    }

    /**
     * Return a raw material modifier for the given date.
     * Accounts for delayed trucks, wet husk, rejected material, excess inventory, etc.
     * The cft_factor is applied multiplicatively to both shift CFT targets.
     */
    private function getPithRawMaterialModifier(string $date): array
    {
        mt_srand(crc32($date.'pith_rawmat'));
        $roll = mt_rand(1, 100);

        // Build factor from within the same seed context
        if ($roll <= 14) {
            $event = 'delayed_truck';
            $factor = mt_rand(80, 90) / 100.0;      // -10% to -20% CFT
        } elseif ($roll <= 21) {
            $event = 'excess_inventory';
            $factor = mt_rand(105, 110) / 100.0;    // +5% to +10% CFT
        } elseif ($roll <= 28) {
            $event = 'wet_husk';
            $factor = mt_rand(75, 85) / 100.0;      // -15% to -25% CFT
        } elseif ($roll <= 32) {
            $event = 'dry_husk';
            $factor = mt_rand(105, 115) / 100.0;    // +5% to +15% CFT
        } elseif ($roll <= 38) {
            $event = 'rejected_material';
            $factor = mt_rand(70, 80) / 100.0;      // -20% to -30% CFT
        } elseif ($roll <= 52) {
            $event = 'loading_delay';
            $factor = mt_rand(90, 98) / 100.0;      // minor startup delay effect
        } else {
            $event = 'normal';
            $factor = 1.0;
        }

        mt_srand();

        return ['event' => $event, 'cft_factor' => $factor];
    }

    /**
     * Return a seasonal CFT base multiplier based on the month.
     * Rainy season (Jun–Sep): wet husk reduces output by ~8%.
     * Summer (Mar–May): motor overheating risk increases, slight output reduction.
     */
    private function getPithSeasonalModifier(Carbon $day): array
    {
        $month = $day->month;

        // Rainy season (June–September): husk quality degrades from moisture
        if (in_array($month, [6, 7, 8, 9], true)) {
            return ['cft_base_factor' => 0.92, 'reason' => 'rainy_season'];
        }

        // Summer heat (March–May): motor overheating risk, brief stoppages
        if (in_array($month, [3, 4, 5], true)) {
            return ['cft_base_factor' => 0.96, 'reason' => 'summer_heat'];
        }

        return ['cft_base_factor' => 1.0, 'reason' => 'normal'];
    }
}
