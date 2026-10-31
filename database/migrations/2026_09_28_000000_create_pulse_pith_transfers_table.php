<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pith moved from one location to another. A transfer is written to the ledger as two
 * pulse_pith_inventory rows sharing its pith_transfer_id — an outward at the source and an
 * inward at the destination — so stock totals, reports, snapshots and batch closing all pick
 * it up without knowing transfers exist. Deleting the transfer deletes both rows with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pulse_pith_transfers', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('from_pith_location_id');
            $table->unsignedInteger('to_pith_location_id');
            $table->date('date');
            $table->double('cft');
            $table->string('note', 255)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index('from_pith_location_id', 'idx_pith_transfers_from');
            $table->index('to_pith_location_id', 'idx_pith_transfers_to');
            $table->foreign('from_pith_location_id')->references('id')->on('pulse_pith_locations')->cascadeOnDelete();
            $table->foreign('to_pith_location_id')->references('id')->on('pulse_pith_locations')->cascadeOnDelete();
        });

        Schema::table('pulse_pith_inventory', function (Blueprint $table) {
            $table->unsignedInteger('pith_transfer_id')->nullable()->after('pith_production_id');

            $table->index('pith_transfer_id', 'idx_pith_inventory_transfer');
            $table->foreign('pith_transfer_id', 'fk_pith_inventory_transfer')
                ->references('id')->on('pulse_pith_transfers')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pulse_pith_inventory', function (Blueprint $table) {
            $table->dropForeign('fk_pith_inventory_transfer');
            $table->dropIndex('idx_pith_inventory_transfer');
            $table->dropColumn('pith_transfer_id');
        });

        Schema::dropIfExists('pulse_pith_transfers');
    }
};
