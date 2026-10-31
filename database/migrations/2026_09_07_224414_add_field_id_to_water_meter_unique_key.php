<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Put `field_id` into the water meter uniqueness key.
 *
 * The key was (device_id, meter_date, litres_counter), which treats a counter value as
 * unique per device per day across ALL fields. But the table carries two independent
 * counters — d27 (total) and d16 (husk) — so if they ever hold the same value on the
 * same date, whichever reading arrives second violates the key and is lost. Their
 * ranges are far apart today, but a counter reset or a replaced meter starts a field
 * back near zero, and the pipeline already treats resets as routine.
 *
 * The rest of the stack is field-aware: ProcessDeviceDataForModWaterJob partitions its
 * duplicate detection by (device_id, field_id), docs/iot/water.md documents the key as
 * including field_id, and the sibling pulse_device_data_growbags and
 * pulse_device_data_water_tank_levels tables both carry field_id in theirs. Only this
 * table drifted, and this brings it back in line, matching the growbags index name.
 *
 * Adding a column to a unique key can only make it more permissive, so no existing row
 * can violate the new key and no data cleanup is required.
 */
return new class extends Migration
{
    protected string $table = 'pulse_device_data_water_meters';

    protected string $oldIndex = 'uniq_device_date_counter';

    protected string $newIndex = 'unique_device_field_date_counter';

    public function up(): void
    {
        if ($this->hasIndex($this->newIndex)) {
            return;
        }

        // Widen first, narrow second: the table is never left without a uniqueness
        // guard, and a failure here leaves the original key untouched.
        Schema::table($this->table, function (Blueprint $table) {
            $table->unique(
                ['device_id', 'field_id', 'meter_date', 'litres_counter'],
                $this->newIndex
            );
        });

        // Not created by any migration in this repo — it was applied straight to the
        // databases — so it may legitimately be absent on a freshly built schema.
        if ($this->hasIndex($this->oldIndex)) {
            Schema::table($this->table, function (Blueprint $table) {
                $table->dropUnique($this->oldIndex);
            });
        }
    }

    /**
     * Restoring the narrower key fails if any d16/d27 counter collision has been stored
     * since — which is exactly the data this migration exists to permit. Roll back only
     * on a database where that has not happened; the failure is a loud duplicate-key
     * error rather than silent data loss, which is the right way round.
     */
    public function down(): void
    {
        if ($this->hasIndex($this->oldIndex)) {
            return;
        }

        Schema::table($this->table, function (Blueprint $table) {
            $table->unique(['device_id', 'meter_date', 'litres_counter'], $this->oldIndex);
        });

        if ($this->hasIndex($this->newIndex)) {
            Schema::table($this->table, function (Blueprint $table) {
                $table->dropUnique($this->newIndex);
            });
        }
    }

    protected function hasIndex(string $name): bool
    {
        return DB::selectOne(
            'SELECT 1 FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1',
            [$this->table, $name]
        ) !== null;
    }
};
