<?php

namespace App\Jobs\Pulse;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessHusksDailyStockJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $teamId;

    /**
     * Create a new job instance.
     */
    public function __construct(int $teamId)
    {
        $this->teamId = $teamId;
    }

    public function tags(): array
    {
        return ['team:'.$this->teamId];
    }

    /**
     * Execute the job.
     */
    public function handle()
    {
        Log::info("Processing Husk Daily Stock for Team ID: {$this->teamId}");

        try {
            // Target Date = Yesterday
            $dateString = Carbon::yesterday()->toDateString();

            // Get Opening Stock
            // This is the 'closing_stock' of the last entry
            $openingStock = DB::table('pulse_husks_daily_stock')
                ->where('team_id', $this->teamId)
                ->latest('date')
                ->value('closing_stock') ?? 0;

            // Insert Entry using insertOrIgnore to avoid duplicates
            DB::table('pulse_husks_daily_stock')->insertOrIgnore([
                'team_id' => $this->teamId,
                'date' => $dateString,
                'user_id' => null,
                'prod_date' => null,
                'opening_stock' => $openingStock,
                'husks_received' => null,
                'cft' => 0,
                'closing_stock' => $openingStock,
                'husks_correction' => 0,
                'correction_comment' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Reconcile Stock
            // If production data was entered before this cron ran
            DB::statement('CALL reconcile_husks_stock(?, ?)', [$this->teamId, $dateString]);

        } catch (\Exception $e) {
            Log::error("Error processing Husks Daily Stock for Team {$this->teamId}: ".$e->getMessage());
        }
    }
}
