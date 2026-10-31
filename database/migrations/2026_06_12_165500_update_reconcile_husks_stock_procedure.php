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
    DECLARE husks_per_load DECIMAL(12,2) DEFAULT 0;
    
    DECLARE cur CURSOR FOR
        SELECT date
        FROM pulse_husks_daily_stock
        WHERE team_id = p_team_id
          AND date >= p_start_date
        ORDER BY date ASC;
    
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;
    
    SELECT CAST(value AS DECIMAL(10,2))
    INTO husks_per_load
    FROM settings
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
            SELECT sp.shift_date AS date,
                   SUM(
                       CASE 
                           WHEN sp.loads_produced IS NOT NULL 
                           THEN sp.loads_produced 
                           ELSE sp.loads_expected 
                       END
                   ) AS total_loads
            FROM pulse_summary_pith_productions sp
            WHERE sp.team_id = p_team_id
              AND sp.shift_date = cur_date
            GROUP BY sp.shift_date
        ) sub ON hds.date = sub.date AND hds.team_id = p_team_id
        SET hds.loads = COALESCE(sub.total_loads, 0),
            hds.opening_stock = IFNULL(prev_closing, hds.opening_stock),
            hds.closing_stock = IFNULL(prev_closing, hds.opening_stock)
                               + COALESCE(hds.husks_received, 0)
                               + COALESCE(hds.husks_correction, 0)
                               - (COALESCE(sub.total_loads, 0) * husks_per_load),
            hds.prod_date = hds.date
        WHERE hds.team_id = p_team_id AND hds.date = cur_date;
        
        SELECT closing_stock
        INTO prev_closing
        FROM pulse_husks_daily_stock
        WHERE team_id = p_team_id AND date = cur_date
        LIMIT 1;
    END LOOP;
    
    CLOSE cur;
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
    DECLARE husks_per_load DECIMAL(12,2) DEFAULT 0;
    
    DECLARE cur CURSOR FOR
        SELECT date
        FROM husks_daily_stock
        WHERE team_id = p_team_id
          AND date >= p_start_date
        ORDER BY date ASC;
    
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;
    
    SELECT CAST(value AS DECIMAL(10,2))
    INTO husks_per_load
    FROM settings
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
        
        UPDATE husks_daily_stock hds
        LEFT JOIN (
            SELECT sp.shift_date AS date,
                   SUM(
                       CASE 
                           WHEN sp.loads_produced IS NOT NULL 
                           THEN sp.loads_produced 
                           ELSE sp.loads_expected 
                       END
                   ) AS total_loads
            FROM summary_pith_productions sp
            WHERE sp.team_id = p_team_id
              AND sp.shift_date = cur_date
            GROUP BY sp.shift_date
        ) sub ON hds.date = sub.date AND hds.team_id = p_team_id
        SET hds.loads = COALESCE(sub.total_loads, 0),
            hds.opening_stock = IFNULL(prev_closing, hds.opening_stock),
            hds.closing_stock = IFNULL(prev_closing, hds.opening_stock)
                               + COALESCE(hds.husks_received, 0)
                               + COALESCE(hds.husks_correction, 0)
                               - (COALESCE(sub.total_loads, 0) * husks_per_load),
            hds.prod_date = hds.date
        WHERE hds.team_id = p_team_id AND hds.date = cur_date;
        
        SELECT closing_stock
        INTO prev_closing
        FROM husks_daily_stock
        WHERE team_id = p_team_id AND date = cur_date
        LIMIT 1;
    END LOOP;
    
    CLOSE cur;
END
SQL
        );
    }
};
