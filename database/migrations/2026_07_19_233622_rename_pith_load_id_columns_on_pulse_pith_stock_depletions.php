<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('pulse_pith_stock_depletions', function (Blueprint $table) {
            $table->renameColumn('first_pith_load_id', 'first_pith_inventory_id');
            $table->renameColumn('last_pith_load_id', 'last_pith_inventory_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pulse_pith_stock_depletions', function (Blueprint $table) {
            $table->renameColumn('first_pith_inventory_id', 'first_pith_load_id');
            $table->renameColumn('last_pith_inventory_id', 'last_pith_load_id');
        });
    }
};
