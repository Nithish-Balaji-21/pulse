<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pulse_growbag_productions') || Schema::hasTable('growbag_productions')) {
            return;
        }

        Schema::create('pulse_growbag_productions', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('team_id');
            $table->string('device_id')->nullable(); // Nullable for software_only teams
            $table->unsignedInteger('shift_id');
            $table->date('shift_date');
            $table->string('shift_name')->nullable();
            $table->unsignedInteger('growbag_machine_id');
            $table->string('machine_name')->nullable();
            $table->unsignedInteger('staff_id');
            $table->dateTime('start_time');
            $table->dateTime('end_time');
            $table->unsignedInteger('bags_produced')->default(0);
            $table->decimal('eb_units', 10, 2)->default(0);
            $table->decimal('eb_price', 10, 2)->default(0);
            $table->text('comments')->nullable();
            $table->boolean('is_locked')->default(false);
            $table->timestamps();

            $table->foreign('team_id')->references('id')->on('teams')->onDelete('cascade');
            $table->foreign('shift_id')->references('id')->on('pulse_shifts')->onDelete('cascade');
            $table->foreign('staff_id')->references('id')->on('staffs')->onDelete('cascade');
            $table->foreign('growbag_machine_id')->references('id')->on('pulse_master_growbag_machines')->onDelete('cascade');

            $table->index(['team_id', 'shift_date']);
            $table->index('shift_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pulse_growbag_productions');
        Schema::dropIfExists('growbag_productions');
    }
};
