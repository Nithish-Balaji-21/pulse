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
        Schema::rename('pulse_master_fibrebale_machines', 'pulse_master_minibale_machines');
        Schema::rename('pulse_fibre_minibale_productions', 'pulse_minibale_productions');
        Schema::rename('pulse_summary_fibre_mini_bales', 'pulse_summary_mini_bales');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::rename('pulse_summary_mini_bales', 'pulse_summary_fibre_mini_bales');
        Schema::rename('pulse_minibale_productions', 'pulse_fibre_minibale_productions');
        Schema::rename('pulse_master_minibale_machines', 'pulse_master_fibrebale_machines');
    }
};
