<?php

use App\Models\Pulse\Shift;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop starts_next_day / ends_next_day from pulse_shifts. Both flags are fully
     * derivable from the shift times and the team's SHIFT_DAY_ANCHOR setting
     * (start < anchor, end <= anchor), so before dropping we assert every row
     * matches the derivation and stop for review on any mismatch instead of
     * silently migrating inconsistent data.
     */
    public function up(): void
    {
        $this->assertFlagsMatchAnchors();

        Schema::table('pulse_shifts', function (Blueprint $table) {
            $table->dropColumn(['starts_next_day', 'ends_next_day']);
        });

        DB::statement('ALTER TABLE pulse_shifts MODIFY start_time TIME NOT NULL, MODIFY end_time TIME NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE pulse_shifts MODIFY start_time TIME NULL, MODIFY end_time TIME NULL');

        Schema::table('pulse_shifts', function (Blueprint $table) {
            $table->tinyInteger('starts_next_day')->nullable()->default(0)->after('start_time');
            $table->tinyInteger('ends_next_day')->nullable()->default(0)->after('end_time');
        });

        $anchors = DB::table('pulse_settings')
            ->where('key', Shift::ANCHOR_SETTING_KEY)
            ->pluck('value', 'team_id');

        foreach (DB::table('pulse_shifts')->get(['id', 'team_id', 'start_time', 'end_time']) as $shift) {
            $anchor = $anchors[$shift->team_id] ?? Shift::DEFAULT_ANCHOR;

            DB::table('pulse_shifts')->where('id', $shift->id)->update([
                'starts_next_day' => $shift->start_time < $anchor ? 1 : 0,
                'ends_next_day' => $shift->end_time <= $anchor ? 1 : 0,
            ]);
        }
    }

    protected function assertFlagsMatchAnchors(): void
    {
        $anchors = DB::table('pulse_settings')
            ->where('key', Shift::ANCHOR_SETTING_KEY)
            ->pluck('value', 'team_id');

        $mismatches = [];

        foreach (DB::table('pulse_shifts')->get() as $shift) {
            $anchor = $anchors[$shift->team_id] ?? Shift::DEFAULT_ANCHOR;

            if ($shift->start_time === null || $shift->end_time === null) {
                $mismatches[] = "shift #{$shift->id} (team {$shift->team_id}): NULL start/end time";

                continue;
            }

            $expectedStarts = $shift->start_time < $anchor ? 1 : 0;
            $expectedEnds = $shift->end_time <= $anchor ? 1 : 0;

            if ((int) $shift->starts_next_day !== $expectedStarts || (int) $shift->ends_next_day !== $expectedEnds) {
                $mismatches[] = sprintf(
                    'shift #%d (team %d, anchor %s): %s-%s flags [%d,%d], derived [%d,%d]',
                    $shift->id,
                    $shift->team_id,
                    $anchor,
                    $shift->start_time,
                    $shift->end_time,
                    (int) $shift->starts_next_day,
                    (int) $shift->ends_next_day,
                    $expectedStarts,
                    $expectedEnds
                );
            }
        }

        if ($mismatches !== []) {
            Log::error('Aborting drop of pulse_shifts next-day flags — rows inconsistent with team anchors', $mismatches);

            throw new RuntimeException(
                "pulse_shifts flags do not match the team anchors for:\n".implode("\n", $mismatches)
                ."\nReview and fix these rows (or the SHIFT_DAY_ANCHOR settings) before re-running the migration."
            );
        }
    }
};
