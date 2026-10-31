<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pulse_bales_daily_stock')) {
            Schema::drop('pulse_bales_daily_stock');
        }
    }

    public function down(): void
    {
        // Recreate via 2026_08_08_114145 if needed.
    }
};
