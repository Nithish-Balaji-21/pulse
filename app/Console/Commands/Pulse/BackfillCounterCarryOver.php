<?php

namespace App\Console\Commands\Pulse;

use App\Console\Traits\HasDateRangeOption;
use App\Jobs\Pulse\ProcessDeviceDataForFibreJob;
use App\Jobs\Pulse\ProcessDeviceDataForGrowBagsJob;
use App\Jobs\Pulse\ProcessDeviceDataForModBalesJob;
use App\Jobs\Pulse\ProcessDeviceDataForModBlocksJob;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Rebuilds historical production summaries with the counter carry-over applied.
 *
 * Summaries written before the carry-over existed opened each shift day cold, scoring the
 * first reading of the day as a single unit however far the counter had moved overnight. The
 * figures those days were reported with are therefore short by whatever the machine made
 * while its uploads were failing. This re-runs the corrected processing over dates already
 * closed, and prints what moved.
 */
class BackfillCounterCarryOver extends Command
{
    use HasDateRangeOption;

    protected $signature = 'devicedata:backfill-carryover
                                {--team_ids=*}
                                {--modules=* : fibre, growbags, modbales, modblocks — default all}
                                {--start=}
                                {--end=}
                                {--dates=*}
                                {--dry-run : report what would change and write nothing}';

    protected $description = 'Rebuild historical production summaries with the counter carry-over applied';

    /**
     * How each module is recomputed and where its figures are read back from.
     *
     * @var array<string, array{job: class-string, module: string, flag: string, table: string,
     *                          value: string, keys: list<string>, unit: string}>
     */
    private const MODULES = [
        'fibre' => [
            'job' => ProcessDeviceDataForFibreJob::class,
            'module' => 'fibre',
            'flag' => 'modules->pulse->modules->fibre->is_active',
            'table' => 'pulse_summary_fibre_mega_bales',
            'value' => 'bales_produced',
            'keys' => ['shift_date', 'shift_name'],
            'unit' => 'bales',
        ],
        'growbags' => [
            'job' => ProcessDeviceDataForGrowBagsJob::class,
            'module' => 'growbags',
            'flag' => 'modules->pulse->modules->growbags->is_active',
            'table' => 'pulse_summary_growbags',
            'value' => 'bags_produced',
            'keys' => ['shift_date', 'shift_name', 'machine_name'],
            'unit' => 'bags',
        ],
        'modbales' => [
            'job' => ProcessDeviceDataForModBalesJob::class,
            'module' => 'pith',
            'flag' => 'modules->pulse->modules->pith->submodules->bales',
            'table' => 'pulse_summary_mini_bales',
            'value' => 'bales_expected',
            'keys' => ['shift_date'],
            'unit' => 'bales',
        ],
        'modblocks' => [
            'job' => ProcessDeviceDataForModBlocksJob::class,
            'module' => 'pith',
            'flag' => 'modules->pulse->modules->pith->submodules->blocks',
            'table' => 'pulse_summary_pith_blocks',
            'value' => 'blocks_expected',
            'keys' => ['shift_date'],
            'unit' => 'blocks',
        ],
    ];

    public function handle(): int
    {
        $dates = $this->getDatesToProcess();

        // getDatesToProcess() returns an empty array only when the options were rejected
        // (bad format, or end before start) and it has already printed why.
        if (empty($dates)) {
            return self::FAILURE;
        }

        $modules = $this->option('modules') ?: array_keys(self::MODULES);

        if ($unknown = array_diff($modules, array_keys(self::MODULES))) {
            $this->error('Unknown module(s): '.implode(', ', $unknown));

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        $this->line(($dryRun ? 'Dry run — nothing will be written. ' : '')
            .count($dates).' shift date(s), '.implode(', ', $modules));

        $movement = 0;

        foreach ($modules as $module) {
            foreach ($this->targets($module) as $target) {
                $movement += $this->backfill($module, (int) $target->team_id, (int) $target->device_id, $dates, $dryRun);
            }
        }

        $this->newLine();
        $this->line($movement === 0
            ? 'Nothing moved.'
            : ($dryRun ? "Would recover {$movement} unit(s) in total." : "Recovered {$movement} unit(s) in total."));

        return self::SUCCESS;
    }

    /**
     * The teams and devices this module is processed for, mirroring the nightly dispatch.
     *
     * @return Collection<int, object>
     */
    private function targets(string $module)
    {
        $spec = self::MODULES[$module];

        return DB::table('teams')
            ->join('team_pulse', 'teams.id', '=', 'team_pulse.team_id')
            ->join('pulse_devices', 'teams.id', '=', 'pulse_devices.team_id')
            ->where('team_pulse.'.$spec['flag'], true)
            ->where('pulse_devices.module', $spec['module'])
            ->when($this->option('team_ids'), fn ($query, $ids) => $query->whereIn('teams.id', $ids))
            ->select('teams.id as team_id', 'teams.name as team_name', 'pulse_devices.device_id')
            ->get();
    }

    /**
     * Recompute one team's dates and report what moved.
     *
     * The recompute is wrapped in a transaction because the day methods write more than the
     * summary row — they also stamp `shift_id` back onto the raw readings. A dry run that
     * simply skipped the upsert would still have rewritten those, so it runs the real thing
     * and rolls the whole transaction back instead.
     *
     * @param  list<string>  $dates
     * @return int the net units recovered
     */
    private function backfill(string $module, int $teamId, int $deviceId, array $dates, bool $dryRun): int
    {
        $spec = self::MODULES[$module];

        $this->warnOfLaterScheduleChanges($teamId, $dates);

        $before = $this->snapshot($module, $teamId, $dates);
        $after = [];

        DB::beginTransaction();

        try {
            (new $spec['job']($teamId, $deviceId, $dates))->recompute($dates);
            $after = $this->snapshot($module, $teamId, $dates);

            if ($dryRun) {
                throw new RollBackDryRun;
            }

            DB::commit();
        } catch (RollBackDryRun) {
            DB::rollBack();
        } catch (\Throwable $exception) {
            DB::rollBack();
            $this->error("  {$module} / team {$teamId}: {$exception->getMessage()}");

            return 0;
        }

        return $this->report($module, $teamId, $before, $after, $spec['unit']);
    }

    /**
     * The figures currently on record, keyed by whatever identifies a row in this module.
     *
     * @param  list<string>  $dates
     * @return array<string, float>
     */
    private function snapshot(string $module, int $teamId, array $dates): array
    {
        $spec = self::MODULES[$module];

        return DB::table($spec['table'])
            ->where('team_id', $teamId)
            ->whereIn('shift_date', $dates)
            ->get(array_merge($spec['keys'], [$spec['value']]))
            ->mapWithKeys(fn ($row) => [
                implode(' ', array_map(fn (string $key) => $row->{$key}, $spec['keys'])) => (float) $row->{$spec['value']},
            ])
            ->all();
    }

    /**
     * A row the summary already had and now disagrees with is a correction; one it never had
     * is a shift the old run never wrote at all — a day it never processed, or a shift whose
     * readings it dropped. They are worth telling apart: only the first changes a figure
     * somebody has already been shown.
     *
     * @param  array<string, float>  $before
     * @param  array<string, float>  $after
     */
    private function report(string $module, int $teamId, array $before, array $after, string $unit): int
    {
        $rows = [];
        $movement = 0;

        foreach (array_keys($before + $after) as $key) {
            $was = (float) ($before[$key] ?? 0);
            $now = (float) ($after[$key] ?? 0);

            if ($was === $now && array_key_exists($key, $before)) {
                continue;
            }

            $isNew = ! array_key_exists($key, $before);

            if ($isNew && $now === 0.0) {
                continue;
            }

            $movement += (int) ($now - $was);
            $rows[] = [
                $key,
                $isNew ? '—' : (string) (int) $was,
                (string) (int) $now,
                sprintf('%+d', $now - $was),
                $isNew ? 'new row' : '',
            ];
        }

        if ($rows === []) {
            return 0;
        }

        $this->newLine();
        $this->line("<info>{$module}</info> — team {$teamId}");
        $this->table(['', 'was', 'now', $unit, ''], $rows);

        return $movement;
    }

    /**
     * A re-run splits a day under the schedule in force *now*, not the one in force on the
     * day. Where a team's shifts have been edited since, the shift-by-shift figures a
     * backfill writes describe hours nobody worked to — the day's total is corrected, but how
     * it divides between shifts is retrospective fiction. Worth saying out loud before
     * somebody reconciles a June report against it.
     *
     * @param  list<string>  $dates
     */
    private function warnOfLaterScheduleChanges(int $teamId, array $dates): void
    {
        $changedAt = DB::table('pulse_shifts')
            ->where('team_id', $teamId)
            ->max(DB::raw('GREATEST(COALESCE(created_at, 0), COALESCE(updated_at, 0))'));

        $earliest = min($dates);

        if ($changedAt !== null && substr((string) $changedAt, 0, 10) > $earliest) {
            $this->warn("  team {$teamId}: shift schedule last changed ".substr((string) $changedAt, 0, 10)
                .", after {$earliest} — days before that are re-split under today's shifts.");
        }
    }
}

/**
 * Unwinds a dry run's transaction. Never escapes {@see BackfillCounterCarryOver::backfill()}.
 */
final class RollBackDryRun extends RuntimeException {}
