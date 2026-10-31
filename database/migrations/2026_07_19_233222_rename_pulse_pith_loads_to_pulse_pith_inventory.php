<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::rename('pulse_pith_loads', 'pulse_pith_inventory');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::rename('pulse_pith_inventory', 'pulse_pith_loads');
    }
};
