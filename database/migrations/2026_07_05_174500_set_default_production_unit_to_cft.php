<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $settings = DB::table('pulse_settings')
            ->where('key', 'CFT_PER_LOAD')
            ->get();

        foreach ($settings as $setting) {
            DB::table('pulse_settings')
                ->where('id', $setting->id)
                ->update([
                    'value' => 'cft',
                    'options' => 'cft',
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('pulse_settings')
            ->where('key', 'CFT_PER_LOAD')
            ->update([
                'value' => 'loads',
                'updated_at' => now(),
            ]);
    }
};
