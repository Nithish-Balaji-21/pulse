<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * shift_id was tagged on raw readings by ProcessDeviceDataForModBlocksJob but never
     * read back anywhere (unlike the equivalent columns on the fibre raw tables, which
     * IotFibreController/IotFibreWeightController use to color-code raw events by shift).
     * pulse_summary_pith_blocks already carries its own shift_id for that purpose.
     */
    public function up(): void
    {
        Schema::table('pulse_device_data_pith_blocks', function (Blueprint $table) {
            $table->dropColumn('shift_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pulse_device_data_pith_blocks', function (Blueprint $table) {
            $table->unsignedInteger('shift_id')->nullable()->after('eb_units_counter');
        });
    }
};
