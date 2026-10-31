<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily stock ledgers for the two finished goods, mirroring pulse_husks_daily_stock:
 * opening + produced - sold + correction = closing, chained day to day.
 *
 * Production is derived from the summary tables; sales and stock-take corrections are
 * entered by the user, which is what the *_in_stock snapshot on the production row
 * could never express — stock moves on days with no production.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['pulse_bales_daily_stock', 'pulse_blocks_daily_stock'] as $tableName) {
            Schema::create($tableName, function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('team_id');
                $table->unsignedBigInteger('user_id')->nullable()->comment('Who last maintained the entry');
                $table->date('date');

                $table->integer('opening_stock')->default(0);
                $table->integer('produced')->default(0)->comment('Derived from the production summary for this date');
                $table->integer('sold')->nullable()->comment('NULL until the user records a figure for the day');
                $table->integer('correction')->default(0)->comment('Signed stock-take adjustment');
                $table->string('correction_comment', 150)->nullable();
                $table->integer('closing_stock')->default(0);

                $table->timestamps();

                $table->unique(['team_id', 'date']);
                $table->index(['team_id', 'date', 'closing_stock'], 'idx_team_date_closing');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pulse_bales_daily_stock');
        Schema::dropIfExists('pulse_blocks_daily_stock');
    }
};
