<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Tables that had NO timestamps at all → add both
    private array $missingBoth = [
        'asset_outwards',
        'asset_purchases',
        'asset_returns',
        'device_data_daily_summary',
        'projects',
    ];

    // Tables that had created_at but NO updated_at → add updated_at only
    private array $missingOnlyUpdatedAt = [
        'pulse_device_data_vehicles_telemetry',
        'summary_power_states',
    ];

    public function up(): void
    {
        // ── ADD both created_at and updated_at ────────────────────────────
        foreach ($this->missingBoth as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($table) {
                if (! Schema::hasColumn($table, 'created_at')) {
                    $t->timestamp('created_at')->nullable();
                }
                if (! Schema::hasColumn($table, 'updated_at')) {
                    $t->timestamp('updated_at')->nullable();
                }
            });
        }

        // ── ADD only updated_at ───────────────────────────────────────────
        foreach ($this->missingOnlyUpdatedAt as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($table) {
                if (! Schema::hasColumn($table, 'updated_at')) {
                    $t->timestamp('updated_at')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        // ── DROP updated_at that was added ────────────────────────────────
        foreach ($this->missingOnlyUpdatedAt as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($table) {
                if (Schema::hasColumn($table, 'updated_at')) {
                    $t->dropColumn('updated_at');
                }
            });
        }

        // ── DROP both columns that were added ─────────────────────────────
        foreach ($this->missingBoth as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($table) {
                $drop = [];
                if (Schema::hasColumn($table, 'created_at')) {
                    $drop[] = 'created_at';
                }
                if (Schema::hasColumn($table, 'updated_at')) {
                    $drop[] = 'updated_at';
                }
                if (! empty($drop)) {
                    $t->dropColumn($drop);
                }
            });
        }
    }
};
