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
        DB::unprepared('DROP TRIGGER IF EXISTS trg_reconcile_after_insert_expected');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_reconcile_after_insert_produced');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_reconcile_after_update_production');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_reconcile_after_delete_production');

        DB::unprepared('
            CREATE TRIGGER trg_reconcile_after_insert_expected
            AFTER INSERT ON pulse_summary_pith_productions
            FOR EACH ROW
            BEGIN
                IF NEW.cft_expected IS NOT NULL THEN
                    CALL reconcile_husks_stock(NEW.team_id, NEW.shift_date);
                END IF;
            END
        ');

        DB::unprepared('
            CREATE TRIGGER trg_reconcile_after_insert_produced
            AFTER INSERT ON pulse_summary_pith_productions
            FOR EACH ROW
            BEGIN
                CALL reconcile_husks_stock(NEW.team_id, NEW.shift_date);
            END
        ');

        DB::unprepared('
            CREATE TRIGGER trg_reconcile_after_update_production
            AFTER UPDATE ON pulse_summary_pith_productions
            FOR EACH ROW
            BEGIN
                IF (NEW.cft_produced <> OLD.cft_produced
                    OR (OLD.cft_produced IS NULL AND NEW.cft_produced IS NOT NULL))
                   THEN
                    CALL reconcile_husks_stock(NEW.team_id, NEW.shift_date);
                END IF;

                IF (NEW.cft_expected <> OLD.cft_expected
                    OR (OLD.cft_expected IS NULL AND NEW.cft_expected IS NOT NULL))
                   AND NEW.cft_produced IS NULL THEN
                    CALL reconcile_husks_stock(NEW.team_id, NEW.shift_date);
                END IF;
            END
        ');

        DB::unprepared('
            CREATE TRIGGER trg_reconcile_after_delete_production
            AFTER DELETE ON pulse_summary_pith_productions
            FOR EACH ROW
            BEGIN
                CALL reconcile_husks_stock(OLD.team_id, OLD.shift_date);
            END
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_reconcile_after_insert_expected');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_reconcile_after_insert_produced');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_reconcile_after_update_production');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_reconcile_after_delete_production');

        DB::unprepared('
            CREATE TRIGGER trg_reconcile_after_insert_expected
            AFTER INSERT ON pulse_summary_pith_productions
            FOR EACH ROW
            BEGIN
                IF NEW.loads_expected IS NOT NULL AND NEW.is_locked = 0 THEN
                    CALL reconcile_husks_stock(NEW.team_id, NEW.shift_date);
                END IF;
            END
        ');

        DB::unprepared('
            CREATE TRIGGER trg_reconcile_after_insert_produced
            AFTER INSERT ON pulse_summary_pith_productions
            FOR EACH ROW
            BEGIN
                IF NEW.is_locked = 0 THEN
                    CALL reconcile_husks_stock(NEW.team_id, NEW.shift_date);
                END IF;
            END
        ');

        DB::unprepared('
            CREATE TRIGGER trg_reconcile_after_update_production
            AFTER UPDATE ON pulse_summary_pith_productions
            FOR EACH ROW
            BEGIN
                IF (NEW.loads_produced <> OLD.loads_produced
                    OR (OLD.loads_produced IS NULL AND NEW.loads_produced IS NOT NULL))
                   AND NEW.is_locked = 0 THEN
                    CALL reconcile_husks_stock(NEW.team_id, NEW.shift_date);
                END IF;

                IF (NEW.loads_expected <> OLD.loads_expected
                    OR (OLD.loads_expected IS NULL AND NEW.loads_expected IS NOT NULL))
                   AND NEW.loads_produced IS NULL
                   AND NEW.is_locked = 0 THEN
                    CALL reconcile_husks_stock(NEW.team_id, NEW.shift_date);
                END IF;
            END
        ');

        DB::unprepared('
            CREATE TRIGGER trg_reconcile_after_delete_production
            AFTER DELETE ON pulse_summary_pith_productions
            FOR EACH ROW
            BEGIN
                IF OLD.is_locked = 0 THEN
                    CALL reconcile_husks_stock(OLD.team_id, OLD.shift_date);
                END IF;
            END
        ');
    }
};
