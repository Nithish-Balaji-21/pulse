<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $triggers = DB::select(<<<'SQL'
            SELECT TRIGGER_NAME, EVENT_MANIPULATION, EVENT_OBJECT_TABLE, ACTION_TIMING, ACTION_STATEMENT
            FROM information_schema.TRIGGERS
            WHERE TRIGGER_SCHEMA = DATABASE()
              AND (
                    ACTION_STATEMENT LIKE '%husks_daily_stock%'
                 OR ACTION_STATEMENT LIKE '%husks_daily_stocks%'
              )
        SQL);

        foreach ($triggers as $trigger) {
            $statement = $this->replaceLegacyHusksTableNames($trigger->ACTION_STATEMENT);

            if ($statement === $trigger->ACTION_STATEMENT) {
                continue;
            }

            $triggerName = str_replace('`', '``', $trigger->TRIGGER_NAME);
            $tableName = str_replace('`', '``', $trigger->EVENT_OBJECT_TABLE);
            $timing = $trigger->ACTION_TIMING;
            $event = $trigger->EVENT_MANIPULATION;

            DB::unprepared("DROP TRIGGER IF EXISTS `{$triggerName}`");
            DB::unprepared(
                "CREATE TRIGGER `{$triggerName}` {$timing} {$event} ON `{$tableName}` FOR EACH ROW {$statement}"
            );
        }
    }

    public function down(): void
    {
        // Intentionally no-op. Triggers should keep using the pulse-prefixed table names.
    }

    private function replaceLegacyHusksTableNames(string $statement): string
    {
        $statement = str_replace('`husks_daily_stock`', '`pulse_husks_daily_stock`', $statement);
        $statement = str_replace('`husks_daily_stocks`', '`pulse_husks_daily_stocks`', $statement);

        $statement = preg_replace('/(?<!pulse_)\bhusks_daily_stock\b/', 'pulse_husks_daily_stock', $statement);
        $statement = preg_replace('/(?<!pulse_)\bhusks_daily_stocks\b/', 'pulse_husks_daily_stocks', $statement);

        return $statement;
    }
};
