<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recreates every trigger and stored routine in the current schema so they are
 * owned by the user this app connects as.
 *
 * A schema restored from another server (or from `database/schema/mysql-schema.sql`)
 * carries that server's DEFINER on its triggers and procedures. When that account
 * doesn't exist here, MySQL refuses every statement that touches them:
 *
 *   SQLSTATE[HY000]: General error: 1449 The user specified as a definer
 *   ('imik'@'localhost') does not exist
 *
 * which is what takes down `ProcessDeviceDataForPithJob` (insert into
 * pulse_summary_pith_productions fires the husk-stock reconcile triggers) and
 * `ProcessHusksDailyStockJob` (CALL reconcile_husks_stock).
 *
 * Dropping and recreating without a DEFINER clause makes MySQL stamp the current
 * user, so the objects work again. Bodies are copied verbatim — nothing else changes.
 */
class RepairDatabaseDefiners extends Command
{
    protected $signature = 'db:repair-definers {--dry-run : List the objects that would be recreated without changing anything}';

    protected $description = 'Recreate triggers and stored routines so they are owned by the current database user';

    public function handle(): int
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->error('This command only applies to MySQL connections.');

            return self::FAILURE;
        }

        $currentUser = DB::selectOne('SELECT CURRENT_USER() AS user')->user;
        $this->info("Connected as {$currentUser} on database ".DB::getDatabaseName().'.');

        $repaired = $this->repairTriggers($currentUser) + $this->repairRoutines($currentUser);

        if ($repaired === 0) {
            $this->info('Nothing to repair — every trigger and routine is already owned by the current user.');
        }

        return self::SUCCESS;
    }

    private function repairTriggers(string $currentUser): int
    {
        $triggers = DB::select(<<<'SQL'
            SELECT TRIGGER_NAME, DEFINER, EVENT_MANIPULATION, EVENT_OBJECT_TABLE, ACTION_TIMING, ACTION_STATEMENT
            FROM information_schema.TRIGGERS
            WHERE TRIGGER_SCHEMA = DATABASE()
            ORDER BY EVENT_OBJECT_TABLE, ACTION_TIMING, TRIGGER_NAME
        SQL);

        $count = 0;

        foreach ($triggers as $trigger) {
            if ($this->matchesCurrentUser($trigger->DEFINER, $currentUser)) {
                continue;
            }

            $count++;
            $this->line("Trigger `{$trigger->TRIGGER_NAME}` on `{$trigger->EVENT_OBJECT_TABLE}` (definer {$trigger->DEFINER})");

            if ($this->option('dry-run')) {
                continue;
            }

            $name = $this->quote($trigger->TRIGGER_NAME);
            $table = $this->quote($trigger->EVENT_OBJECT_TABLE);

            DB::unprepared("DROP TRIGGER IF EXISTS {$name}");
            DB::unprepared(sprintf(
                'CREATE TRIGGER %s %s %s ON %s FOR EACH ROW %s',
                $name,
                $trigger->ACTION_TIMING,
                $trigger->EVENT_MANIPULATION,
                $table,
                $trigger->ACTION_STATEMENT
            ));
        }

        return $count;
    }

    private function repairRoutines(string $currentUser): int
    {
        $routines = DB::select(<<<'SQL'
            SELECT ROUTINE_NAME, ROUTINE_TYPE, DEFINER
            FROM information_schema.ROUTINES
            WHERE ROUTINE_SCHEMA = DATABASE()
            ORDER BY ROUTINE_TYPE, ROUTINE_NAME
        SQL);

        $count = 0;

        foreach ($routines as $routine) {
            if ($this->matchesCurrentUser($routine->DEFINER, $currentUser)) {
                continue;
            }

            $count++;
            $type = strtoupper($routine->ROUTINE_TYPE); // PROCEDURE or FUNCTION
            $this->line("{$type} `{$routine->ROUTINE_NAME}` (definer {$routine->DEFINER})");

            if ($this->option('dry-run')) {
                continue;
            }

            $name = $this->quote($routine->ROUTINE_NAME);
            $row = (array) DB::selectOne("SHOW CREATE {$type} {$name}");
            $ddl = $row["Create {$routine->ROUTINE_TYPE}"] ?? $row['Create '.ucfirst(strtolower($type))] ?? null;

            if (! is_string($ddl) || $ddl === '') {
                $this->warn("  Skipped — could not read the definition of `{$routine->ROUTINE_NAME}`.");
                $count--;

                continue;
            }

            // Drop the DEFINER clause so MySQL stamps the current user on recreate.
            $ddl = preg_replace('/\sDEFINER=(`(?:[^`]|``)*`|\'(?:[^\']|\'\')*\')@(`(?:[^`]|``)*`|\'(?:[^\']|\'\')*\')/i', '', $ddl, 1);

            DB::unprepared("DROP {$type} IF EXISTS {$name}");
            DB::unprepared($ddl);
        }

        return $count;
    }

    private function matchesCurrentUser(string $definer, string $currentUser): bool
    {
        return str_replace('`', '', $definer) === str_replace('`', '', $currentUser);
    }

    private function quote(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }
}
