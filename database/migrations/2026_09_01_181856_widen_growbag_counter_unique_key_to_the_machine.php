<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A growbag device carries one counter per machine, not one for the factory: each `field_id`
 * is its own sequence, and two machines are free to sit on the same number on the same day —
 * they habitually do, since every machine's counter starts wherever that machine was
 * commissioned.
 *
 * The key this replaces spanned (device, date, counter) only, so the second machine to reach
 * a number another had already reached that day was a duplicate. Readings arrive through
 * insertOrIgnore, so that machine lost the punch silently rather than failing loudly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pulse_device_data_growbags', function (Blueprint $table) {
            $table->dropUnique('unique_device_date_counter');
            $table->unique(
                ['device_id', 'field_id', 'growbag_date', 'growbags_counter'],
                'unique_device_field_date_counter'
            );
        });
    }

    public function down(): void
    {
        Schema::table('pulse_device_data_growbags', function (Blueprint $table) {
            $table->dropUnique('unique_device_field_date_counter');
            $table->unique(
                ['device_id', 'growbag_date', 'growbags_counter'],
                'unique_device_date_counter'
            );
        });
    }
};
