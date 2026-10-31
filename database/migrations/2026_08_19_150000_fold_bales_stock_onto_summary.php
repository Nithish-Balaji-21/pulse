<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pulse_summary_mini_bales')) {
            return;
        }

        // Step 1: Add stock columns
        Schema::table('pulse_summary_mini_bales', function (Blueprint $table) {
            if (! Schema::hasColumn('pulse_summary_mini_bales', 'sold')) {
                $table->unsignedInteger('sold')->nullable()->after('bales_produced');
            }
            if (! Schema::hasColumn('pulse_summary_mini_bales', 'correction')) {
                $table->integer('correction')->default(0)->after('sold');
            }
            if (! Schema::hasColumn('pulse_summary_mini_bales', 'correction_comment')) {
                $table->string('correction_comment', 150)->nullable()->after('correction');
            }
            if (! Schema::hasColumn('pulse_summary_mini_bales', 'bales_in_stock')) {
                $table->integer('bales_in_stock')->default(0)->after('correction_comment');
            }
        });

        // Step 2: Consolidate multiple shift rows into 1 row per team+date
        // Sum bales, take earliest start_time and latest end_time, keep first staff_id
        $duplicates = DB::table('pulse_summary_mini_bales')
            ->select('team_id', 'shift_date')
            ->groupBy('team_id', 'shift_date')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $dup) {
            $rows = DB::table('pulse_summary_mini_bales')
                ->where('team_id', $dup->team_id)
                ->where('shift_date', $dup->shift_date)
                ->orderBy('id')
                ->get();

            $keepId = $rows->first()->id;
            $mergeIds = $rows->slice(1)->pluck('id');

            DB::table('pulse_summary_mini_bales')
                ->where('id', $keepId)
                ->update([
                    'bales_produced' => $rows->sum('bales_produced'),
                    'bales_expected' => $rows->sum('bales_expected'),
                    'start_time' => $rows->min('start_time'),
                    'end_time' => $rows->max('end_time'),
                ]);

            DB::table('pulse_summary_mini_bales')
                ->whereIn('id', $mergeIds->all())
                ->delete();
        }

        // Step 3: Copy stock data from daily ledger
        if (Schema::hasTable('pulse_bales_daily_stock')) {
            DB::statement('
                UPDATE pulse_summary_mini_bales s
                INNER JOIN pulse_bales_daily_stock d
                    ON d.team_id = s.team_id AND d.date = s.shift_date
                SET
                    s.sold = d.sold,
                    s.correction = COALESCE(d.correction, 0),
                    s.correction_comment = d.correction_comment
            ');
        }

        // Step 4: Drop shift columns + add unique index
        Schema::table('pulse_summary_mini_bales', function (Blueprint $table) {
            if (Schema::hasColumn('pulse_summary_mini_bales', 'shift_id')) {
                $table->dropColumn('shift_id');
            }
            if (Schema::hasColumn('pulse_summary_mini_bales', 'shift_name')) {
                $table->dropColumn('shift_name');
            }
        });

        // Add unique constraint (may fail if somehow duplicates remain)
        try {
            Schema::table('pulse_summary_mini_bales', function (Blueprint $table) {
                $table->unique(['team_id', 'shift_date']);
            });
        } catch (Throwable $e) {
            // Index may already exist
        }

        // Step 5: Create reconcile procedure
        $this->createReconcileProcedure();

        // Step 6: Backfill bales_in_stock for all teams
        $teams = DB::table('pulse_summary_mini_bales')->distinct()->pluck('team_id');
        foreach ($teams as $teamId) {
            $earliest = DB::table('pulse_summary_mini_bales')->where('team_id', $teamId)->min('shift_date');
            if ($earliest) {
                DB::statement('CALL reconcile_bales_stock(?, ?)', [(int) $teamId, $earliest]);
            }
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP PROCEDURE IF EXISTS `reconcile_bales_stock`');

        if (Schema::hasTable('pulse_summary_mini_bales')) {
            Schema::table('pulse_summary_mini_bales', function (Blueprint $table) {
                foreach (['sold', 'correction', 'correction_comment', 'bales_in_stock'] as $column) {
                    if (Schema::hasColumn('pulse_summary_mini_bales', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }

    private function createReconcileProcedure(): void
    {
        DB::unprepared('DROP PROCEDURE IF EXISTS `reconcile_bales_stock`');
        DB::unprepared(<<<'SQL'
CREATE PROCEDURE `reconcile_bales_stock`(
    IN p_team_id INT,
    IN p_start_date DATE
)
BEGIN
    DECLARE done INT DEFAULT 0;
    DECLARE cur_date DATE;
    DECLARE prev_closing INT DEFAULT NULL;

    DECLARE cur CURSOR FOR
        SELECT shift_date FROM pulse_summary_mini_bales
        WHERE team_id = p_team_id AND shift_date >= p_start_date
        ORDER BY shift_date ASC;

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;

    SELECT bales_in_stock INTO prev_closing
    FROM pulse_summary_mini_bales
    WHERE team_id = p_team_id AND shift_date < p_start_date
    ORDER BY shift_date DESC LIMIT 1;

    SET done = 0;
    OPEN cur;

    read_loop: LOOP
        FETCH cur INTO cur_date;
        IF done = 1 THEN LEAVE read_loop; END IF;

        UPDATE pulse_summary_mini_bales
        SET bales_in_stock =
            IFNULL(prev_closing, 0)
            + CASE WHEN bales_produced > 0 THEN bales_produced ELSE COALESCE(bales_expected, 0) END
            - COALESCE(sold, 0) + COALESCE(correction, 0),
            updated_at = NOW()
        WHERE team_id = p_team_id AND shift_date = cur_date;

        SELECT bales_in_stock INTO prev_closing
        FROM pulse_summary_mini_bales
        WHERE team_id = p_team_id AND shift_date = cur_date LIMIT 1;
    END LOOP;

    CLOSE cur;
END
SQL);
    }
};
