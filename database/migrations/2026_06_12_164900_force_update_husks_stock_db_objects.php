<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->updateTriggers();
    }

    public function down(): void
    {
        // Intentionally no-op. Database objects should keep using pulse-prefixed tables.
    }

    private function updateTriggers(): void
    {
        $triggers = DB::select(<<<'SQL'
            SELECT TRIGGER_NAME, EVENT_MANIPULATION, EVENT_OBJECT_TABLE, ACTION_TIMING, ACTION_STATEMENT
            FROM information_schema.TRIGGERS
            WHERE TRIGGER_SCHEMA = DATABASE()
              AND (
                    ACTION_STATEMENT LIKE '%husks_daily_stock%'
                 OR ACTION_STATEMENT LIKE '%husks_daily_stocks%'
                 OR ACTION_STATEMENT LIKE '%husk_daily_stock%'
                 OR ACTION_STATEMENT LIKE '%husk_daily_stocks%'
              )
        SQL);

        foreach ($triggers as $trigger) {
            $statement = $this->replaceLegacyNames($trigger->ACTION_STATEMENT);

            if ($statement === $trigger->ACTION_STATEMENT) {
                continue;
            }

            $triggerName = str_replace('`', '``', $trigger->TRIGGER_NAME);
            $tableName = str_replace('`', '``', $trigger->EVENT_OBJECT_TABLE);

            DB::unprepared("DROP TRIGGER IF EXISTS `{$triggerName}`");
            DB::unprepared(sprintf(
                'CREATE TRIGGER `%s` %s %s ON `%s` FOR EACH ROW %s',
                $triggerName,
                $trigger->ACTION_TIMING,
                $trigger->EVENT_MANIPULATION,
                $tableName,
                $statement
            ));
        }
    }

    private function replaceLegacyNames(string $sql): string
    {
        $database = DB::getDatabaseName();

        $replacements = [
            "`{$database}`.`husks_daily_stock`" => "`{$database}`.`pulse_husks_daily_stock`",
            "`{$database}`.`husks_daily_stocks`" => "`{$database}`.`pulse_husks_daily_stocks`",
            "`{$database}`.`husk_daily_stock`" => "`{$database}`.`pulse_husks_daily_stock`",
            "`{$database}`.`husk_daily_stocks`" => "`{$database}`.`pulse_husks_daily_stocks`",
            "{$database}.husks_daily_stock" => "{$database}.pulse_husks_daily_stock",
            "{$database}.husks_daily_stocks" => "{$database}.pulse_husks_daily_stocks",
            "{$database}.husk_daily_stock" => "{$database}.pulse_husks_daily_stock",
            "{$database}.husk_daily_stocks" => "{$database}.pulse_husks_daily_stocks",
            '`husks_daily_stock`' => '`pulse_husks_daily_stock`',
            '`husks_daily_stocks`' => '`pulse_husks_daily_stocks`',
            '`husk_daily_stock`' => '`pulse_husks_daily_stock`',
            '`husk_daily_stocks`' => '`pulse_husks_daily_stocks`',
        ];

        $sql = str_replace(array_keys($replacements), array_values($replacements), $sql);

        $sql = preg_replace('/(?<!pulse_)\bhusks_daily_stock\b/', 'pulse_husks_daily_stock', $sql);
        $sql = preg_replace('/(?<!pulse_)\bhusks_daily_stocks\b/', 'pulse_husks_daily_stocks', $sql);
        $sql = preg_replace('/(?<!pulse_)\bhusk_daily_stock\b/', 'pulse_husks_daily_stock', $sql);
        $sql = preg_replace('/(?<!pulse_)\bhusk_daily_stocks\b/', 'pulse_husks_daily_stocks', $sql);

        return $sql;
    }
};
