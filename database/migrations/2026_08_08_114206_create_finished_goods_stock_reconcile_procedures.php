<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reconciliation procedures for the two finished-goods stock ledgers, following
 * reconcile_husks_stock: walk every day from a start date, refresh the derived
 * production figure, and re-chain opening/closing forward.
 *
 * Two things differ from the husk procedure. Missing days are seeded first, so a
 * ledger edited months later cannot leave a hole in the chain; and `produced` reads
 * the actual only when one was entered, falling back to the IoT estimate, which is
 * the same rule the production lists display.
 */
return new class extends Migration
{
    /**
     * @return array<string, array{ledger: string, summary: string, produced: string, expected: string}>
     */
    private function modules(): array
    {
        return [
            'reconcile_bales_stock' => [
                'ledger' => 'pulse_bales_daily_stock',
                'summary' => 'pulse_summary_mini_bales',
                'produced' => 'bales_produced',
                'expected' => 'bales_expected',
            ],
            'reconcile_blocks_stock' => [
                'ledger' => 'pulse_blocks_daily_stock',
                'summary' => 'pulse_summary_pith_blocks',
                'produced' => 'blocks_produced',
                'expected' => 'blocks_expected',
            ],
        ];
    }

    public function up(): void
    {
        foreach ($this->modules() as $procedure => $module) {
            DB::unprepared("DROP PROCEDURE IF EXISTS `{$procedure}`");
            DB::unprepared(<<<SQL
CREATE PROCEDURE `{$procedure}`(
    IN p_team_id INT,
    IN p_start_date DATE
)
BEGIN
    DECLARE done INT DEFAULT 0;
    DECLARE cur_date DATE;
    DECLARE prev_closing INT DEFAULT NULL;

    DECLARE cur CURSOR FOR
        SELECT date
        FROM {$module['ledger']}
        WHERE team_id = p_team_id
          AND date >= p_start_date
        ORDER BY date ASC;

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;

    -- A production day with no ledger row would break the running balance, so seed
    -- one before walking the chain.
    INSERT INTO {$module['ledger']}
        (team_id, date, opening_stock, produced, sold, correction, closing_stock, created_at, updated_at)
    SELECT p_team_id, s.shift_date, 0, 0, NULL, 0, 0, NOW(), NOW()
    FROM (
        SELECT DISTINCT shift_date
        FROM {$module['summary']}
        WHERE team_id = p_team_id
          AND shift_date >= p_start_date
    ) s
    WHERE NOT EXISTS (
        SELECT 1 FROM {$module['ledger']} d
        WHERE d.team_id = p_team_id AND d.date = s.shift_date
    );

    -- Carry the balance in from the last day before the window.
    SELECT closing_stock
    INTO prev_closing
    FROM {$module['ledger']}
    WHERE team_id = p_team_id
      AND date < p_start_date
    ORDER BY date DESC
    LIMIT 1;

    -- The lookup above trips the NOT FOUND handler when the window starts at the
    -- very first ledger row; clear it or the loop below exits immediately.
    SET done = 0;

    OPEN cur;

    read_loop: LOOP
        FETCH cur INTO cur_date;

        IF done = 1 THEN
            LEAVE read_loop;
        END IF;

        UPDATE {$module['ledger']} d
        LEFT JOIN (
            SELECT
                p.shift_date AS date,
                SUM(
                    CASE
                        WHEN p.{$module['produced']} > 0
                        THEN p.{$module['produced']}
                        ELSE COALESCE(p.{$module['expected']}, 0)
                    END
                ) AS total_produced
            FROM {$module['summary']} p
            WHERE p.team_id = p_team_id
              AND p.shift_date = cur_date
            GROUP BY p.shift_date
        ) sub
            ON d.date = sub.date
        SET
            d.produced = COALESCE(sub.total_produced, 0),
            d.opening_stock = IFNULL(prev_closing, d.opening_stock),
            d.closing_stock =
                IFNULL(prev_closing, d.opening_stock)
                + COALESCE(sub.total_produced, 0)
                - COALESCE(d.sold, 0)
                + COALESCE(d.correction, 0)
        WHERE d.team_id = p_team_id
          AND d.date = cur_date;

        SELECT closing_stock
        INTO prev_closing
        FROM {$module['ledger']}
        WHERE team_id = p_team_id
          AND date = cur_date
        LIMIT 1;

    END LOOP;

    CLOSE cur;
END
SQL
            );
        }
    }

    public function down(): void
    {
        foreach (array_keys($this->modules()) as $procedure) {
            DB::unprepared("DROP PROCEDURE IF EXISTS `{$procedure}`");
        }
    }
};
