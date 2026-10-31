<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Drop and recreate stored procedure reconcile_husks_stock using HUSKS_PER_LOAD directly without date logics
        DB::unprepared('DROP PROCEDURE IF EXISTS `reconcile_husks_stock`');
        DB::unprepared(<<<'SQL'
CREATE PROCEDURE `reconcile_husks_stock`(
    IN p_team_id INT,
    IN p_start_date DATE
)
BEGIN
    DECLARE done INT DEFAULT 0;
    DECLARE cur_date DATE;
    DECLARE prev_closing DECIMAL(12,2) DEFAULT NULL;
    DECLARE husks_per_cft DECIMAL(12,6) DEFAULT 0;

    DECLARE cur CURSOR FOR
        SELECT date
        FROM pulse_husks_daily_stock
        WHERE team_id = p_team_id
          AND date >= p_start_date
        ORDER BY date ASC;

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;

    SELECT CAST(value AS DECIMAL(12,6))
    INTO husks_per_cft
    FROM pulse_settings
    WHERE team_id = p_team_id
      AND module = 'pith'
      AND `key` = 'HUSKS_PER_LOAD'
    LIMIT 1;

    OPEN cur;

    read_loop: LOOP
        FETCH cur INTO cur_date;

        IF done = 1 THEN
            LEAVE read_loop;
        END IF;

        UPDATE pulse_husks_daily_stock hds
        LEFT JOIN (
            SELECT
                sp.shift_date AS date,
                SUM(
                    CASE
                        WHEN sp.cft_produced IS NOT NULL
                        THEN sp.cft_produced
                        ELSE sp.cft_expected
                    END
                ) AS total_cft
            FROM pulse_summary_pith_productions sp
            WHERE sp.team_id = p_team_id
              AND sp.shift_date = cur_date
            GROUP BY sp.shift_date
        ) sub
            ON hds.date = sub.date
           AND hds.team_id = p_team_id
        SET
            hds.cft = COALESCE(sub.total_cft, 0),
            hds.opening_stock = IFNULL(prev_closing, hds.opening_stock),
            hds.closing_stock =
                IFNULL(prev_closing, hds.opening_stock)
                + COALESCE(hds.husks_received, 0)
                + COALESCE(hds.husks_correction, 0)
                - (COALESCE(sub.total_cft, 0) * husks_per_cft),
            hds.prod_date = hds.date
        WHERE hds.team_id = p_team_id
          AND hds.date = cur_date;

        SELECT closing_stock
        INTO prev_closing
        FROM pulse_husks_daily_stock
        WHERE team_id = p_team_id
          AND date = cur_date
        LIMIT 1;

    END LOOP;

    CLOSE cur;

END
SQL
        );

        // 2. Drop and recreate trigger trg_reconcile_before_update_stock using HUSKS_PER_LOAD directly without date logics
        DB::unprepared('DROP TRIGGER IF EXISTS `trg_reconcile_before_update_stock`');
        DB::unprepared(<<<'SQL'
CREATE TRIGGER `trg_reconcile_before_update_stock` BEFORE UPDATE ON `pulse_husks_daily_stock` FOR EACH ROW
BEGIN
    DECLARE husks_per_cft DECIMAL(12,6) DEFAULT 0;

    SELECT COALESCE(CAST(value AS DECIMAL(12,6)),0)
    INTO husks_per_cft
    FROM pulse_settings
    WHERE team_id = NEW.team_id
      AND module = 'pith'
      AND `key` = 'HUSKS_PER_LOAD'
    LIMIT 1;

    SET NEW.closing_stock =
          COALESCE(NEW.opening_stock,0)
        + COALESCE(NEW.husks_received,0)
        + COALESCE(NEW.husks_correction,0)
        - (COALESCE(NEW.cft,0) * COALESCE(husks_per_cft,0));
END
SQL
        );

        // 3. Apply stock correction on 2026-05-01 for Team 2 (Alam Cocos) to force closing stock to 0
        $mayFirstStock = DB::table('pulse_husks_daily_stock')
            ->where('team_id', 2)
            ->where('date', '2026-05-01')
            ->first();

        if ($mayFirstStock) {
            $husksPerCft = (float) DB::table('pulse_settings')
                ->where('team_id', 2)
                ->where('module', 'pith')
                ->where('key', 'HUSKS_PER_LOAD')
                ->value('value') ?? 1.89;

            $minDate = DB::table('pulse_husks_daily_stock')
                ->where('team_id', 2)
                ->min('date');

            if ($minDate && $minDate < '2026-05-01') {
                DB::statement('CALL reconcile_husks_stock(?, ?)', [2, $minDate]);

                $mayFirstStock = DB::table('pulse_husks_daily_stock')
                    ->where('team_id', 2)
                    ->where('date', '2026-05-01')
                    ->first();
            }

            $consumption = $mayFirstStock->cft * $husksPerCft;
            $received = (float) ($mayFirstStock->husks_received ?? 0);
            $requiredCorrection = $consumption - $mayFirstStock->opening_stock - $received;

            DB::table('pulse_husks_daily_stock')
                ->where('id', $mayFirstStock->id)
                ->update([
                    'husks_correction' => $requiredCorrection,
                    'correction_comment' => 'Forced stock reset to 0',
                ]);
        }

        // 4. Run reconciliation for all teams starting from their earliest stock record
        $teams = DB::table('teams')->pluck('id');
        foreach ($teams as $teamId) {
            $minDate = DB::table('pulse_husks_daily_stock')
                ->where('team_id', $teamId)
                ->min('date');

            if ($minDate) {
                DB::statement('CALL reconcile_husks_stock(?, ?)', [$teamId, $minDate]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::unprepared('DROP PROCEDURE IF EXISTS `reconcile_husks_stock`');

        // Determine if cft or loads is the column name at the time of procedure creation in rollback
        $columnName = Schema::hasColumn('pulse_husks_daily_stock', 'cft') ? 'cft' : 'loads';

        DB::unprepared("
CREATE PROCEDURE `reconcile_husks_stock`(
    IN p_team_id INT,
    IN p_start_date DATE
)
BEGIN
    DECLARE done INT DEFAULT 0;
    DECLARE cur_date DATE;
    DECLARE prev_closing DECIMAL(12,2) DEFAULT NULL;
    DECLARE husks_per_load DECIMAL(12,2) DEFAULT 0;
    DECLARE cft_per_load DECIMAL(12,2) DEFAULT 300;
    DECLARE husks_per_cft DECIMAL(12,6) DEFAULT 0;

    DECLARE cur CURSOR FOR
        SELECT date
        FROM pulse_husks_daily_stock
        WHERE team_id = p_team_id
          AND date >= p_start_date
        ORDER BY date ASC;

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;

    SELECT CAST(value AS DECIMAL(10,2))
    INTO husks_per_load
    FROM pulse_settings
    WHERE team_id = p_team_id
      AND module = 'pith'
      AND `key` = 'HUSKS_PER_LOAD'
    LIMIT 1;

    SELECT CAST(NULLIF(value_remark, '') AS DECIMAL(10,2))
    INTO cft_per_load
    FROM pulse_settings
    WHERE team_id = p_team_id
      AND module = 'pith'
      AND `key` = 'CFT_PER_LOAD'
    LIMIT 1;

    SET cft_per_load = IFNULL(NULLIF(cft_per_load, 0), 300);
    SET husks_per_cft = husks_per_load / cft_per_load;

    OPEN cur;

    read_loop: LOOP
        FETCH cur INTO cur_date;

        IF done = 1 THEN
            LEAVE read_loop;
        END IF;

        UPDATE pulse_husks_daily_stock hds
        LEFT JOIN (
            SELECT
                sp.shift_date AS date,
                SUM(
                    CASE
                        WHEN sp.cft_produced IS NOT NULL
                        THEN sp.cft_produced
                        ELSE sp.cft_expected
                    END
                ) AS total_cft
            FROM pulse_summary_pith_productions sp
            WHERE sp.team_id = p_team_id
              AND sp.shift_date = cur_date
            GROUP BY sp.shift_date
        ) sub
            ON hds.date = sub.date
           AND hds.team_id = p_team_id
        SET
            hds.{$columnName} = COALESCE(sub.total_cft, 0),
            hds.opening_stock = IFNULL(prev_closing, hds.opening_stock),
            hds.closing_stock =
                IFNULL(prev_closing, hds.opening_stock)
                + COALESCE(hds.husks_received, 0)
                + COALESCE(hds.husks_correction, 0)
                - (COALESCE(sub.total_cft, 0) * husks_per_cft),
            hds.prod_date = hds.date
        WHERE hds.team_id = p_team_id
          AND hds.date = cur_date;

        SELECT closing_stock
        INTO prev_closing
        FROM pulse_husks_daily_stock
        WHERE team_id = p_team_id
          AND date = cur_date
        LIMIT 1;

    END LOOP;

    CLOSE cur;

END
        ");

        DB::unprepared('DROP TRIGGER IF EXISTS `trg_reconcile_before_update_stock`');
        DB::unprepared("
CREATE TRIGGER `trg_reconcile_before_update_stock` BEFORE UPDATE ON `pulse_husks_daily_stock` FOR EACH ROW
BEGIN
    DECLARE husks_per_load DECIMAL(10,2) DEFAULT 0;

    SELECT COALESCE(CAST(value AS DECIMAL(10,2)),0)
    INTO husks_per_load
    FROM pulse_settings
    WHERE team_id = NEW.team_id
      AND module = 'pith'
      AND `key` = 'HUSKS_PER_LOAD'
    LIMIT 1;

    SET NEW.closing_stock =
          COALESCE(NEW.opening_stock,0)
        + COALESCE(NEW.husks_received,0)
        + COALESCE(NEW.husks_correction,0)
        - (COALESCE(NEW.{$columnName},0) * COALESCE(husks_per_load,0));
END
        ");
    }
};
