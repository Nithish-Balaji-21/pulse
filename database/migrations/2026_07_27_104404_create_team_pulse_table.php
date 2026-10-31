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
        Schema::create('team_pulse', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('team_id');
            $table->foreign('team_id')->references('id')->on('teams')->cascadeOnDelete();
            $table->unsignedInteger('factory_address_id')->nullable();
            $table->foreign('factory_address_id')->references('id')->on('team_address')->nullOnDelete();
            $table->json('modules')->nullable();
            $table->text('coordinates')->nullable();
            $table->date('license_valid')->nullable();
            $table->date('installed_date')->nullable();
            $table->boolean('is_active')->default(1);
            $table->timestamps();
            $table->unique('team_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('team_pulse');
    }
};
