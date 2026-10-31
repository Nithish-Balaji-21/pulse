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
        Schema::table('pulse_minibale_productions', function (Blueprint $table) {
            $table->renameColumn('fibrebale_machine_id', 'minibale_machine_id');
        });

        Schema::table('pulse_summary_mini_bales', function (Blueprint $table) {
            $table->renameColumn('fibrebale_machine_id', 'minibale_machine_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pulse_summary_mini_bales', function (Blueprint $table) {
            $table->renameColumn('minibale_machine_id', 'fibrebale_machine_id');
        });

        Schema::table('pulse_minibale_productions', function (Blueprint $table) {
            $table->renameColumn('minibale_machine_id', 'fibrebale_machine_id');
        });
    }
};
