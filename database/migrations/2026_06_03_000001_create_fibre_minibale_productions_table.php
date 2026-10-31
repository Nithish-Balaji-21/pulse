<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pulse_fibre_minibale_productions') || Schema::hasTable('fibre_minibale_productions')) {
            return;
        }

        Schema::create('pulse_fibre_minibale_productions', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('team_id');
            $table->string('device_id')->nullable(); // Nullable for software_only teams
            $table->unsignedInteger('shift_id');
            $table->date('shift_date');
            $table->string('shift_name')->nullable();
            $table->unsignedBigInteger('fibrebale_machine_id');
            $table->string('machine_name')->nullable();
            $table->unsignedInteger('staff_id');
            $table->dateTime('start_time');
            $table->dateTime('end_time');
            $table->unsignedInteger('bales_produced')->default(0);
            $table->decimal('eb_units', 10, 2)->default(0);
            $table->decimal('eb_price', 10, 2)->default(0);
            $table->text('comments')->nullable();
            $table->boolean('is_locked')->default(false);
            $table->timestamps();

            $table->foreign('team_id')->references('id')->on('teams')->onDelete('cascade');
            $table->foreign('shift_id')->references('id')->on('pulse_shifts')->onDelete('cascade');
            $table->foreign('staff_id')->references('id')->on('staffs')->onDelete('cascade');
            $table->foreign('fibrebale_machine_id')->references('id')->on('pulse_master_fibrebale_machines')->onDelete('cascade');

            $table->index(['team_id', 'shift_date']);
            $table->index('shift_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pulse_fibre_minibale_productions');
        Schema::dropIfExists('fibre_minibale_productions');
    }
};
