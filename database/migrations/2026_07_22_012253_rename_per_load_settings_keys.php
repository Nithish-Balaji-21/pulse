<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('pulse_settings')->where('key', 'HUSKS_PER_LOAD')->update(['key' => 'HUSKS_PER_CFT', 'updated_at' => now()]);
        DB::table('pulse_settings')->where('key', 'EB_UNITS_PER_LOAD')->update(['key' => 'EB_UNITS_PER_CFT', 'updated_at' => now()]);
        DB::table('pulse_settings')->where('key', 'CFT_PER_LOAD')->update(['key' => 'PRODUCTION_UNIT', 'updated_at' => now()]);

        // reconcile_husks_stock and trg_reconcile_before_update_stock both look up
        // the husks-per-CFT factor by literal key name — recreate them to reference
        // the renamed key.
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
      AND `key` = 'HUSKS_PER_CFT'
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
      AND `key` = 'HUSKS_PER_CFT'
    LIMIT 1;

    SET NEW.closing_stock =
          COALESCE(NEW.opening_stock,0)
        + COALESCE(NEW.husks_received,0)
        + COALESCE(NEW.husks_correction,0)
        - (COALESCE(NEW.cft,0) * COALESCE(husks_per_cft,0));
END
SQL
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
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

        DB::table('pulse_settings')->where('key', 'HUSKS_PER_CFT')->update(['key' => 'HUSKS_PER_LOAD', 'updated_at' => now()]);
        DB::table('pulse_settings')->where('key', 'EB_UNITS_PER_CFT')->update(['key' => 'EB_UNITS_PER_LOAD', 'updated_at' => now()]);
        DB::table('pulse_settings')->where('key', 'PRODUCTION_UNIT')->update(['key' => 'CFT_PER_LOAD', 'updated_at' => now()]);
    }
};
