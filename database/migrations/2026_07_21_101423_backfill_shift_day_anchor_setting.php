<?php

use App\Models\Pulse\Shift;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add the per-team SHIFT_DAY_ANCHOR setting. The anchor is backfilled from each
     * team's current schedule (earliest same-day shift start — the value the old
     * starts_next_day flags were derived against), falling back to 08:00:00 for
     * teams without shifts. Backfilling a flat 08:00 would instantly invalidate
     * teams whose day starts earlier (e.g. 06:00 or 07:30 factories).
     */
    public function up(): void
    {
        $teamIds = DB::table('teams')->pluck('id');

        foreach ($teamIds as $teamId) {
            $exists = DB::table('pulse_settings')
                ->where('team_id', $teamId)
                ->where('key', Shift::ANCHOR_SETTING_KEY)
                ->exists();

            if ($exists) {
                continue;
            }

            $anchor = DB::table('pulse_shifts')
                ->where('team_id', $teamId)
                ->where('starts_next_day', 0)
                ->min('start_time') ?? Shift::DEFAULT_ANCHOR;

            DB::table('pulse_settings')->insert([
                'team_id' => $teamId,
                'module' => 'shifts',
                'key' => Shift::ANCHOR_SETTING_KEY,
                'text' => 'Shift day anchor (start of the 24-hour production day)',
                'value' => $anchor,
                'options' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('pulse_settings')->where('key', Shift::ANCHOR_SETTING_KEY)->delete();
    }
};
