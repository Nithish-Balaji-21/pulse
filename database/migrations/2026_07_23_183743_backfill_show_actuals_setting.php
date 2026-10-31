<?php

use App\Models\Team;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $now = now();

        // Use raw DB to avoid Team model referencing team_pulse which may not exist yet
        DB::table('teams')->get()
            ->each(function ($team) use ($now) {
                $products = json_decode($team->products ?? '{}', true) ?? [];
                $hasPith = $products['pulse']['modules']['pith']['is_active'] ?? false;

                if ($hasPith) {
                    DB::table('pulse_settings')->updateOrInsert(
                        [
                            'team_id' => $team->id,
                            'module' => 'pith',
                            'key' => 'SHOW_ACTUALS',
                        ],
                        [
                            'text' => 'Show Actuals (manually entered production) alongside Estimated?',
                            'value' => 'yes',
                            'options' => 'no, yes',
                            'value_remark' => null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]
                    );
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('pulse_settings')
            ->where('module', 'pith')
            ->where('key', 'SHOW_ACTUALS')
            ->delete();
    }
};
