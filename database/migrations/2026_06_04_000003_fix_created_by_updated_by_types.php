<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $fixCreatedBy = [
        'attendance' => ['created_by', 'updated_by'],
        'expenses' => ['created_by', 'updated_by'],
        'pith_loads' => ['created_by', 'updated_by'],
        'pith_stock_depletions' => ['created_by', 'updated_by'],
        'pith_units' => ['created_by', 'updated_by'],
        'settings' => ['updated_by'],
        'shifts' => ['created_by', 'updated_by'],
        'staffs' => ['created_by', 'updated_by'],
        'summary_fibre_mini_bales' => ['created_by', 'updated_by'],
        'summary_pith_productions' => ['created_by', 'updated_by'],
    ];

    public function up(): void
    {
        // ── ADD created_by to settings table ─────────────────────────────
        if (Schema::hasTable('settings') && ! Schema::hasColumn('settings', 'created_by')) {
            Schema::table('settings', function (Blueprint $t) {
                $t->integer('created_by')->nullable()->after('value_remark');
            });
        }

        // ── FIX all created_by / updated_by → INT NULL ────────────────────
        foreach ($this->fixCreatedBy as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $col) {
                if (Schema::hasColumn($table, $col)) {
                    DB::statement(
                        "ALTER TABLE `{$table}`
                         MODIFY `{$col}` INT NULL"
                    );
                }
            }
        }
    }

    public function down(): void
    {
        // ── DROP created_by from settings table ───────────────────────────
        if (Schema::hasTable('settings') && Schema::hasColumn('settings', 'created_by')) {
            Schema::table('settings', function (Blueprint $t) {
                $t->dropColumn('created_by');
            });
        }

        // ── REVERT all INT NULL (same type so no actual change) ───────────
        foreach ($this->fixCreatedBy as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $col) {
                if (Schema::hasColumn($table, $col)) {
                    DB::statement(
                        "ALTER TABLE `{$table}`
                         MODIFY `{$col}` INT NULL"
                    );
                }
            }
        }
    }
};
