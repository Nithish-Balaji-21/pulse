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
        // 1. Drop old unit columns and rename load columns in pulse_pith_loads
        Schema::table('pulse_pith_loads', function (Blueprint $table) {
            $columnsToDrop = ['taken_unit_id', 'taken_unit_qty', 'taken_unit_load_ratio', 'entered_unit_id', 'entered_value'];
            foreach ($columnsToDrop as $col) {
                if (Schema::hasColumn('pulse_pith_loads', $col)) {
                    $table->dropColumn($col);
                }
            }

            if (Schema::hasColumn('pulse_pith_loads', 'loads_offloaded')) {
                $table->renameColumn('loads_offloaded', 'cft_offloaded');
            }
            if (Schema::hasColumn('pulse_pith_loads', 'taken_loads_calculated')) {
                $table->renameColumn('taken_loads_calculated', 'taken_cft_calculated');
            }
        });

        // 2. Rename columns in pulse_pith_stock_depletions
        Schema::table('pulse_pith_stock_depletions', function (Blueprint $table) {
            $table->renameColumn('total_loads_offloaded', 'total_cft_offloaded');
            $table->renameColumn('total_loads_taken_calculated', 'total_cft_taken_calculated');
        });

        // 3. Rename columns in pulse_summary_pith_productions
        Schema::table('pulse_summary_pith_productions', function (Blueprint $table) {
            if (Schema::hasColumn('pulse_summary_pith_productions', 'loads_expected')) {
                $table->renameColumn('loads_expected', 'cft_expected');
            }
            if (Schema::hasColumn('pulse_summary_pith_productions', 'loads_produced')) {
                $table->renameColumn('loads_produced', 'cft_produced');
            }
        });

        // 4. Drop the pulse_pith_units table
        Schema::dropIfExists('pulse_pith_units');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Re-create the pulse_pith_units table
        Schema::create('pulse_pith_units', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('team_id');
            $table->string('unit_name', 20);
            $table->double('load_ratio');
            $table->tinyInteger('is_active')->default(1);
            $table->integer('created_by')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->integer('updated_by')->nullable();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });

        // Re-add the columns to pulse_pith_loads
        Schema::table('pulse_pith_loads', function (Blueprint $table) {
            $table->unsignedInteger('taken_unit_id')->nullable();
            $table->float('taken_unit_qty')->nullable();
            $table->float('taken_unit_load_ratio')->nullable();
            $table->unsignedBigInteger('entered_unit_id')->nullable();
            $table->decimal('entered_value', 10, 2)->nullable();
        });
    }
};
