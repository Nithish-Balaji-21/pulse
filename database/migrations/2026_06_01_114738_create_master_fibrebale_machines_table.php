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
        if (Schema::hasTable('pulse_master_fibrebale_machines') || Schema::hasTable('master_fibrebale_machines')) {
            return;
        }

        Schema::create('pulse_master_fibrebale_machines', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('team_id'); // Changed from unsignedBigInteger
            $table->string('machine_name', 100);
            $table->string('machine_code', 50)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(1);
            $table->timestamps();

            // Foreign key
            $table->foreign('team_id')->references('id')->on('teams')->onDelete('cascade');

            // Indexes
            $table->index(['team_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pulse_master_fibrebale_machines');
        Schema::dropIfExists('master_fibrebale_machines');
    }
};
