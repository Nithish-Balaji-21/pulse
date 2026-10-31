<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasIndex('pulse_pith_inventory', ['pith_location_id', 'date'])) {
            return;
        }

        Schema::table('pulse_pith_inventory', function (Blueprint $table) {
            $table->index(['pith_location_id', 'date'], 'idx_pith_inventory_location_date');
        });
    }

    public function down(): void
    {
        if (! Schema::hasIndex('pulse_pith_inventory', ['pith_location_id', 'date'])) {
            return;
        }

        Schema::table('pulse_pith_inventory', function (Blueprint $table) {
            $table->dropIndex('idx_pith_inventory_location_date');
        });
    }
};
