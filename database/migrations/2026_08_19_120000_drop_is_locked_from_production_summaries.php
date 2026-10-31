<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drop legacy is_locked columns and refresh pith production triggers.
 * Editability is enforced by ProductionEntryPolicy (date window from pulse_settings).
 */
return new class extends Migration
{
    private const TABLES = [
        'pulse_summary_pith_blocks',
        'pulse_summary_pith_productions',
        'pulse_summary_mini_bales',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'is_locked')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('is_locked');
            });
        }

        $this->recreatePithProductionTriggers();
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'is_locked')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->boolean('is_locked')->default(false);
            });
        }
    }

    private function recreatePithProductionTriggers(): void
    {
        if (! Schema::hasTable('pulse_summary_pith_productions')) {
            return;
        }

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
};
