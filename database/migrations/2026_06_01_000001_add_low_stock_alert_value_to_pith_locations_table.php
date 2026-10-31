<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('pith_locations', 'low_stock_alert_value')) {
            return;
        }

        Schema::table('pith_locations', function (Blueprint $table) {
            $table->unsignedInteger('low_stock_alert_value')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('pith_locations', 'low_stock_alert_value')) {
            return;
        }

        Schema::table('pith_locations', function (Blueprint $table) {
            $table->dropColumn('low_stock_alert_value');
        });
    }
};
