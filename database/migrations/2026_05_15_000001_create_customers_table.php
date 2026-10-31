<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customers')) {
            return;
        }

        Schema::create('customers', function (Blueprint $table) {
            $table->increments('Customer_ID');
            $table->unsignedBigInteger('team_id');
            $table->string('Customer_Name', 100);
            $table->string('City', 50)->nullable();
            $table->string('Phone', 15)->nullable();
            $table->string('GST_Number', 20)->nullable();
            $table->timestamps();

            $table->index('team_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
