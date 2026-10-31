<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Watering cycles logged against a pith location. A NULL pith_stock_depletion_id marks the
 * location's current (open) batch; closing the location's stock stamps its waterings with the
 * new depletion row, which is how depletion history finds each closed batch's waterings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pulse_pith_waterings', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('pith_location_id');
            $table->unsignedInteger('pith_stock_depletion_id')->nullable();
            $table->date('date');
            $table->unsignedInteger('litres');
            $table->timestamps();

            $table->index(['pith_location_id', 'pith_stock_depletion_id'], 'idx_pith_waterings_location_batch');
            $table->foreign('pith_location_id')->references('id')->on('pulse_pith_locations')->cascadeOnDelete();
            $table->foreign('pith_stock_depletion_id')->references('id')->on('pulse_pith_stock_depletions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pulse_pith_waterings');
    }
};
