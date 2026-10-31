<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed the two ledgers from the history already stored on the production rows.
 *
 * The old bales_in_stock / blocks_in_stock columns hold a level, not a movement, so
 * where a day carries one it is replayed as a stock-take correction: the chain is
 * forced to land exactly on the figure the user recorded, and the gap against
 * opening + produced is what left the yard without ever being entered.
 */
return new class extends Migration
{
    /**
     * @return list<array{ledger: string, summary: string, produced: string, expected: string, stock: string}>
     */
    private function modules(): array
    {
        return [
            [
                'ledger' => 'pulse_bales_daily_stock',
                'summary' => 'pulse_summary_mini_bales',
                'produced' => 'bales_produced',
                'expected' => 'bales_expected',
                'stock' => 'bales_in_stock',
            ],
            [
                'ledger' => 'pulse_blocks_daily_stock',
                'summary' => 'pulse_summary_pith_blocks',
                'produced' => 'blocks_produced',
                'expected' => 'blocks_expected',
                'stock' => 'blocks_in_stock',
            ],
        ];
    }

    public function up(): void
    {
        $now = now();

        foreach ($this->modules() as $module) {
            $days = DB::table($module['summary'])
                ->selectRaw('team_id')
                ->selectRaw('shift_date')
                ->selectRaw("SUM(CASE WHEN {$module['produced']} > 0 THEN {$module['produced']} ELSE COALESCE({$module['expected']}, 0) END) AS produced")
                // The last shift of the day carries the level the user left behind.
                ->selectRaw("SUBSTRING_INDEX(GROUP_CONCAT({$module['stock']} ORDER BY start_time DESC, id DESC), ',', 1) AS recorded_closing")
                ->groupBy('team_id', 'shift_date')
                ->orderBy('team_id')
                ->orderBy('shift_date')
                ->get();

            $rows = [];
            $running = [];

            foreach ($days as $day) {
                $teamId = (int) $day->team_id;
                $opening = $running[$teamId] ?? 0;
                $produced = (int) $day->produced;

                $recordedClosing = $day->recorded_closing === null || $day->recorded_closing === ''
                    ? null
                    : (int) $day->recorded_closing;

                $correction = $recordedClosing === null ? 0 : $recordedClosing - ($opening + $produced);
                $closing = $opening + $produced + $correction;

                $rows[] = [
                    'team_id' => $teamId,
                    'user_id' => null,
                    'date' => $day->shift_date,
                    'opening_stock' => $opening,
                    'produced' => $produced,
                    'sold' => null,
                    'correction' => $correction,
                    'correction_comment' => $correction === 0 ? null : 'Imported from production record',
                    'closing_stock' => $closing,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $running[$teamId] = $closing;
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table($module['ledger'])->insert($chunk);
            }
        }
    }

    public function down(): void
    {
        DB::table('pulse_bales_daily_stock')->delete();
        DB::table('pulse_blocks_daily_stock')->delete();
    }
};
