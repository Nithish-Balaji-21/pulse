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
        Schema::dropIfExists('pulse_minibale_productions');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('pulse_minibale_productions', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('team_id');
            $table->string('device_id')->nullable();
            $table->unsignedInteger('shift_id');
            $table->date('shift_date');
            $table->string('shift_name')->nullable();
            $table->unsignedBigInteger('minibale_machine_id');
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
            $table->foreign('minibale_machine_id')->references('id')->on('pulse_master_minibale_machines')->onDelete('cascade');

            $table->index(['team_id', 'shift_date']);
            $table->index('shift_id');
        });
    }
};
