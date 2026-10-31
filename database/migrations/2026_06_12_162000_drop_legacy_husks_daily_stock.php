<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Drop legacy names if they exist (view or table)
        DB::statement('DROP VIEW IF EXISTS `husks_daily_stock`');
        DB::statement('DROP VIEW IF EXISTS `husks_daily_stocks`');
        DB::statement('DROP TABLE IF EXISTS `husks_daily_stock`');
        DB::statement('DROP TABLE IF EXISTS `husks_daily_stocks`');
    }

    public function down(): void
    {
        // No down migration: keep legacy names removed.
    }
};
