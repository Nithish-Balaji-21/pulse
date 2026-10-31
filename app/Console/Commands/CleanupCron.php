<?php

namespace App\Console\Commands;

use App\Models\Cache;
use App\Models\ImpersonationLog;
use App\Models\Pulse\DeviceData;
use App\Models\Pulse\WeatherDataDaily;
use App\Models\Pulse\WeatherDataHourly;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupCron extends Command
{
    protected $signature = 'data:cleanup';

    protected $description = 'Clean the old, stale data';

    protected const DELETE_BATCH_SIZE = 1000;

    public function handle()
    {
        $this->info('Cleanup start...');

        // Demo environment churns through generated data much faster, so device
        // data/summaries and weather data get pruned far more aggressively there.
        $isDemo = app()->environment('demo');

        // Delete device_data records, in batches to avoid long-held locks/large
        // binlog writes against this multi-million row table.
        $deviceDataCutoff = $isDemo ? Carbon::now()->subMonths(6) : Carbon::now()->subYears(2);
        $this->deleteInBatches(fn () => DeviceData::where('datetime', '<', $deviceDataCutoff)->limit(self::DELETE_BATCH_SIZE)->delete());

        // Delete device_data_daily_summary records
        $this->deleteInBatches(fn () => DB::table('pulse_device_data_daily_summary')->where('date', '<', $deviceDataCutoff)->limit(self::DELETE_BATCH_SIZE)->delete());

        // Delete device_data_* tables
        $deviceDataTables = [
            'pulse_device_data_fibre_bales',
            'pulse_device_data_fibre_mega_bales_weight',
            'pulse_device_data_pith_blocks',
            'pulse_device_data_water_meters',
            'pulse_device_data_growbags',
            'pulse_device_data_cft_calc',
            'pulse_device_data_water_tank_levels',
        ];
        foreach ($deviceDataTables as $table) {
            $this->deleteInBatches(fn () => DB::table($table)->where('created_at', '<', $deviceDataCutoff)->limit(self::DELETE_BATCH_SIZE)->delete());
        }

        // Delete weather_data_daily records. The 'date' column only has day
        // granularity, which is too coarse for demo's 5-day window, so demo
        // filters on created_at instead.
        if ($isDemo) {
            WeatherDataDaily::where('created_at', '<', Carbon::now()->subDays(5))->delete();
        } else {
            WeatherDataDaily::where('date', '<', Carbon::now()->subMonth()->toDateString())->delete();
        }

        // Delete notifications older than a month. This table is a delivery log, and the
        // in-app inbox only ever queries the last 7 days (NotificationsController::index),
        // so a month leaves ample headroom. Batched — it is the fastest-growing of these.
        $oneMonthAgo = Carbon::now()->subMonth();
        $this->deleteInBatches(fn () => DB::table('notifications')->where('created_at', '<', $oneMonthAgo)->limit(self::DELETE_BATCH_SIZE)->delete());

        // Delete weather_data_hourly records. Same day-granularity caveat as above
        // applies to demo's 5-hour window.
        if ($isDemo) {
            WeatherDataHourly::where('created_at', '<', Carbon::now()->subHours(5))->delete();
        } else {
            WeatherDataHourly::where('date', '<', Carbon::now()->subDays(10)->toDateString())->delete();
        }

        // Delete impersonation logs older than two months
        $this->deleteInBatches(fn () => ImpersonationLog::where('created_at', '<', Carbon::now()->subMonths(2))->limit(self::DELETE_BATCH_SIZE)->delete());

        // Delete cache records older than 2 minutes
        $twoMinutesAgo = Carbon::now()->subMinutes(2)->timestamp;
        Cache::where('expiration', '<=', $twoMinutesAgo)->delete();

        $this->info('Cleanup end!');
    }

    /**
     * Repeatedly run a bounded delete query until it stops matching rows, so large
     * tables are cleaned up in small batches instead of one long-held delete.
     */
    protected function deleteInBatches(\Closure $deleteBatch): void
    {
        do {
            $deleted = $deleteBatch();
        } while ($deleted > 0);
    }
}
