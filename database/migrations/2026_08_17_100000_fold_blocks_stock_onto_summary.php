<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pulse_summary_pith_blocks')) {
            return;
        }

        Schema::table('pulse_summary_pith_blocks', function (Blueprint $table) {
            if (! Schema::hasColumn('pulse_summary_pith_blocks', 'sold')) {
                $table->unsignedInteger('sold')->nullable()->after('blocks_produced');
            }
            if (! Schema::hasColumn('pulse_summary_pith_blocks', 'correction')) {
                $table->integer('correction')->default(0)->after('sold');
            }
            if (! Schema::hasColumn('pulse_summary_pith_blocks', 'correction_comment')) {
                $table->string('correction_comment', 150)->nullable()->after('correction');
            }
        });

        if (Schema::hasTable('pulse_blocks_daily_stock')) {
            DB::statement('
                UPDATE pulse_summary_pith_blocks s
                INNER JOIN pulse_blocks_daily_stock d
                    ON d.team_id = s.team_id AND d.date = s.shift_date
                SET
                    s.sold = d.sold,
                    s.correction = COALESCE(d.correction, 0),
                    s.correction_comment = d.correction_comment
            ');
        }

        $this->createSummaryReconcileProcedure();

        $teams = DB::table('pulse_summary_pith_blocks')->distinct()->pluck('team_id');
        foreach ($teams as $teamId) {
            $earliest = DB::table('pulse_summary_pith_blocks')->where('team_id', $teamId)->min('shift_date');
            if ($earliest) {
                DB::statement('CALL reconcile_blocks_stock(?, ?)', [(int) $teamId, $earliest]);
            }
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP PROCEDURE IF EXISTS `reconcile_blocks_stock`');

        if (Schema::hasTable('pulse_summary_pith_blocks')) {
            Schema::table('pulse_summary_pith_blocks', function (Blueprint $table) {
                foreach (['sold', 'correction', 'correction_comment'] as $column) {
                    if (Schema::hasColumn('pulse_summary_pith_blocks', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }

    private function createSummaryReconcileProcedure(): void
    {
        DB::unprepared('DROP PROCEDURE IF EXISTS `reconcile_blocks_stock`');
        DB::unprepared(<<<'SQL'
CREATE PROCEDURE `reconcile_blocks_stock`(
    IN p_team_id INT,
    IN p_start_date DATE
)
BEGIN
    DECLARE done INT DEFAULT 0;
    DECLARE cur_date DATE;
    DECLARE prev_closing INT DEFAULT NULL;

    DECLARE cur CURSOR FOR
        SELECT shift_date FROM pulse_summary_pith_blocks
        WHERE team_id = p_team_id AND shift_date >= p_start_date
        ORDER BY shift_date ASC;

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;

    SELECT blocks_in_stock INTO prev_closing
    FROM pulse_summary_pith_blocks
    WHERE team_id = p_team_id AND shift_date < p_start_date
    ORDER BY shift_date DESC LIMIT 1;

    SET done = 0;
    OPEN cur;

    read_loop: LOOP
        FETCH cur INTO cur_date;
        IF done = 1 THEN LEAVE read_loop; END IF;

        UPDATE pulse_summary_pith_blocks
        SET blocks_in_stock =
            IFNULL(prev_closing, 0)
            + CASE WHEN blocks_produced > 0 THEN blocks_produced ELSE COALESCE(blocks_expected, 0) END
            - COALESCE(sold, 0) + COALESCE(correction, 0),
            updated_at = NOW()
        WHERE team_id = p_team_id AND shift_date = cur_date;

        SELECT blocks_in_stock INTO prev_closing
        FROM pulse_summary_pith_blocks
        WHERE team_id = p_team_id AND shift_date = cur_date LIMIT 1;
    END LOOP;

    CLOSE cur;
END
SQL);
    }
};
