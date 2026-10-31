<?php

use App\Models\Pulse\Shift;
use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Collapse pith blocks to one Total Shift row per production day (anchor → next anchor),
 * then enforce UNIQUE(team_id, shift_date) and drop shift_id.
 *
 * Idempotent for schema-dump CI: mysql-schema.sql already has the end-state table
 * (uniq_pith_blocks_team_date, no shift_id), so ALTER steps no-op when already applied.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pulse_summary_pith_blocks')) {
            return;
        }

        $hasShiftId = Schema::hasColumn('pulse_summary_pith_blocks', 'shift_id');
        $hasShiftName = Schema::hasColumn('pulse_summary_pith_blocks', 'shift_name');
        $hasOldUnique = Schema::hasIndex('pulse_summary_pith_blocks', 'uniq_pith_blocks_team_date_shift');
        $hasNewUnique = Schema::hasIndex('pulse_summary_pith_blocks', 'uniq_pith_blocks_team_date');

        // Schema dump / already migrated: nothing left to collapse or alter.
        if (! $hasShiftId && ! $hasOldUnique && $hasNewUnique) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | MARKER 1: Fetch All Unique (team_id, shift_date) Groups
        |--------------------------------------------------------------------------
        */
        $groups = DB::table('pulse_summary_pith_blocks')
            ->select('team_id', 'shift_date')
            ->groupBy('team_id', 'shift_date')
            ->orderBy('team_id')
            ->orderBy('shift_date')
            ->get();

        $anchorCache = [];
        $affectedTeams = [];
        $earliestDates = [];

        /*
        |--------------------------------------------------------------------------
        | MARKER 2: Process Historical Rows Case-by-Case
        |--------------------------------------------------------------------------
        */
        foreach ($groups as $group) {
            $teamId = $group->team_id;
            $shiftDate = $group->shift_date;

            // Track teams and earliest date for stock reconcile later
            $affectedTeams[$teamId] = true;
            if (! isset($earliestDates[$teamId]) || $shiftDate < $earliestDates[$teamId]) {
                $earliestDates[$teamId] = $shiftDate;
            }

            // Cache team anchor (default 08:00 AM)
            if (! isset($anchorCache[$teamId])) {
                $anchorCache[$teamId] = Shift::anchorForTeam($teamId);
            }
            $anchor = $anchorCache[$teamId];

            // 24h window calculation (e.g. 08:00 AM to 08:00 AM next day)
            $startTime = Carbon::parse($shiftDate)->setTimeFromTimeString($anchor)->toDateTimeString();
            $endTime = Carbon::parse($startTime)->addDay()->toDateTimeString();

            // Fetch all rows for this specific team and date
            $rows = DB::table('pulse_summary_pith_blocks')
                ->where('team_id', $teamId)
                ->where('shift_date', $shiftDate)
                ->orderBy('id', 'asc')
                ->get();

            if ($rows->count() > 1) {
                /*
                |--------------------------------------------------------------------------
                | MARKER 2A: Dual-Shift Days (Shift 1 + Shift 2 Merge)
                |--------------------------------------------------------------------------
                | Combine counts into 1 survivor row and delete sibling rows.
                */
                $survivor = $rows->first();
                $siblingIds = $rows->pluck('id')->reject(fn ($id) => $id === $survivor->id)->all();

                $totalExpected = $rows->sum('blocks_expected');
                $totalProduced = $rows->sum('blocks_produced');

                // Pick non-null staff, comments, device
                $staffId = $rows->pluck('staff_id')->filter()->first();
                $deviceId = $rows->pluck('device_id')->filter()->first();
                $comments = $rows->pluck('comments')->filter()->unique()->implode('; ');

                $update = [
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                    'blocks_expected' => $totalExpected,
                    'blocks_produced' => $totalProduced,
                    'staff_id' => $staffId,
                    'device_id' => $deviceId,
                    'comments' => $comments ?: null,
                    'updated_at' => now(),
                ];

                if ($hasShiftName) {
                    $update['shift_name'] = 'Total Shift';
                }

                // Update survivor row to Total Shift + full 24h window + combined totals
                DB::table('pulse_summary_pith_blocks')
                    ->where('id', $survivor->id)
                    ->update($update);

                // Delete sibling rows
                DB::table('pulse_summary_pith_blocks')
                    ->whereIn('id', $siblingIds)
                    ->delete();

            } else {
                /*
                |--------------------------------------------------------------------------
                | MARKER 2B & 2C: Solo Shift (Shift 1 / Shift 2) or NULL Shift Rows
                |--------------------------------------------------------------------------
                | Expand window to full 24h, set name to Total Shift, keep counts as-is.
                */
                $row = $rows->first();

                $update = [
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                    'updated_at' => now(),
                ];

                if ($hasShiftName) {
                    $update['shift_name'] = 'Total Shift';
                }

                DB::table('pulse_summary_pith_blocks')
                    ->where('id', $row->id)
                    ->update($update);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | MARKER 3: Pre-flight Safety Assert
        |--------------------------------------------------------------------------
        | Verify every (team_id, shift_date) has exactly 1 row before altering unique key.
        */
        $duplicates = DB::table('pulse_summary_pith_blocks')
            ->select('team_id', 'shift_date', DB::raw('COUNT(*) as cnt'))
            ->groupBy('team_id', 'shift_date')
            ->having('cnt', '>', 1)
            ->get();

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException('Migration failed: Duplicate (team_id, shift_date) rows still exist!');
        }

        /*
        |--------------------------------------------------------------------------
        | MARKER 4: Database Schema Update (Unique Key & Drop Column)
        |--------------------------------------------------------------------------
        */
        if ($hasOldUnique) {
            Schema::table('pulse_summary_pith_blocks', function (Blueprint $table) {
                $table->dropUnique('uniq_pith_blocks_team_date_shift');
            });
        }

        if (! Schema::hasIndex('pulse_summary_pith_blocks', 'uniq_pith_blocks_team_date')) {
            Schema::table('pulse_summary_pith_blocks', function (Blueprint $table) {
                $table->unique(['team_id', 'shift_date'], 'uniq_pith_blocks_team_date');
            });
        }

        if ($hasShiftId) {
            Schema::table('pulse_summary_pith_blocks', function (Blueprint $table) {
                $table->dropColumn('shift_id');
            });
        }

        /*
        |--------------------------------------------------------------------------
        | MARKER 5: Reconcile Stock Ledgers for All Affected Teams
        |--------------------------------------------------------------------------
        | Raw DB::table merges/deletes skip ReconcilesFinishedGoodsStock observers.
        */
        if (Schema::hasTable('pulse_blocks_daily_stock')) {
            foreach (array_keys($affectedTeams) as $tId) {
                $startDate = $earliestDates[$tId] ?? '2026-01-01';
                DB::statement('CALL reconcile_blocks_stock(?, ?)', [(int) $tId, $startDate]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('pulse_summary_pith_blocks')) {
            return;
        }

        if (Schema::hasIndex('pulse_summary_pith_blocks', 'uniq_pith_blocks_team_date')) {
            Schema::table('pulse_summary_pith_blocks', function (Blueprint $table) {
                $table->dropUnique('uniq_pith_blocks_team_date');
            });
        }

        if (! Schema::hasColumn('pulse_summary_pith_blocks', 'shift_id')) {
            Schema::table('pulse_summary_pith_blocks', function (Blueprint $table) {
                $table->unsignedInteger('shift_id')->nullable()->after('shift_date');
            });
        }

        if (! Schema::hasIndex('pulse_summary_pith_blocks', 'uniq_pith_blocks_team_date_shift')) {
            Schema::table('pulse_summary_pith_blocks', function (Blueprint $table) {
                $table->unique(['team_id', 'shift_date', 'shift_id'], 'uniq_pith_blocks_team_date_shift');
            });
        }
    }
};
