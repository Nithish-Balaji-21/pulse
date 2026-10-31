<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pulse_pith_inventory_summary', function (Blueprint $table) {
            $table->id();
            $table->date('summary_date');
            $table->unsignedInteger('team_id');
            $table->unsignedInteger('location_id');
            $table->decimal('inward', 10, 2)->default(0);
            $table->decimal('outward', 10, 2)->default(0);
            $table->decimal('in_stock', 10, 2)->default(0);
            $table->timestamps();

            $table->unique(['summary_date', 'team_id', 'location_id'], 'uq_pith_inventory_summary');
            $table->index(['location_id', 'summary_date'], 'idx_location_summary_date');
            $table->index(['team_id', 'summary_date'], 'idx_team_summary_date');

            $table->foreign('team_id')->references('id')->on('teams');
            $table->foreign('location_id')->references('id')->on('pulse_pith_locations');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pulse_pith_inventory_summary');
    }
};
