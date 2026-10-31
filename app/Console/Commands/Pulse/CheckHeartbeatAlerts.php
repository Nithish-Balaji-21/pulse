<?php

namespace App\Console\Commands\Pulse;

use App\Mail\HeartbeatDownAlertMail;
use App\Mail\HeartbeatRecoveryMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class CheckHeartbeatAlerts extends Command
{
    protected $signature = 'alerts:check-heartbeat';

    protected $description = 'Heartbeat monitoring and alert system';

    // 🔧 adjustable timings (minutes)
    private int $downAfterMinutes = 10;

    private int $alertAfterMinutes = 25;

    public function handle()
    {
        try {
            $this->updateDownStatus();
            $recipients = $this->getRecipients();
            $this->sendRecoveryEmails($recipients);
            $this->updateAliveStatus();
            $this->sendAlertEmails($recipients);
        } catch (\Throwable $e) {
            Log::error('alerts:check-heartbeat failed: '.$e->getMessage());
            $this->error('Heartbeat alert command failed. Check logs.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function updateDownStatus(): void
    {
        DB::table('pulse_alerts_heartbeat_monitor')
            ->whereRaw("last_seen < (NOW() - INTERVAL {$this->downAfterMinutes} MINUTE)")
            ->where('current_status', '<>', 'DOWN')
            ->update(['current_status' => 'DOWN']);
    }

    private function updateAliveStatus(): void
    {
        DB::table('pulse_alerts_heartbeat_monitor')
            ->whereRaw("last_seen >= (NOW() - INTERVAL {$this->downAfterMinutes} MINUTE)")
            ->where(function ($query) {
                $query->where('current_status', '<>', 'ALIVE')
                    ->orWhere('alert_sent', '<>', 0);
            })
            ->update([
                'current_status' => 'ALIVE',
                'alert_sent' => 0,
            ]);
    }

    private function getRecipients(): array
    {
        $setting = DB::table('pulse_superadmin_settings')
            ->where('setting_key', 'TECHNICAL_ALERT_EMAILS')
            ->where('is_active', 1)
            ->value('setting_value');

        if (! $setting) {
            return [];
        }

        $decoded = json_decode($setting, true);
        $rawRecipients = is_array($decoded) ? $decoded : explode(',', $setting);

        return collect($rawRecipients)
            ->map(fn ($email) => trim($email))
            ->map(fn ($email) => trim($email, " \t\n\r\0\x0B\"'[]"))
            ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))
            ->filter()
            ->values()
            ->all();
    }

    private function sendRecoveryEmails(array $recipients): void
    {
        if (empty($recipients)) {
            Log::warning('heartbeat:check-alerts skipped sending emails: no active recipients in product_settings.');

            return;
        }

        $recoveredDevices = DB::table('pulse_alerts_heartbeat_monitor')
            ->select('client_id', 'last_seen')
            ->whereRaw("last_seen >= (NOW() - INTERVAL {$this->downAfterMinutes} MINUTE)")
            ->where('alert_sent', 1)
            ->get();

        foreach ($recoveredDevices as $device) {
            try {
                if ((app()->environment('local') || app()->environment('demo')) && (int) env('APP_SEND_EMAIL', 0) !== 1) {
                    Log::info("alerts:check-heartbeat: Skipping recovery email for client {$device->client_id} in local/demo environment.");

                    continue;
                }

                Mail::to($recipients)->send(
                    new HeartbeatRecoveryMail(
                        $device->client_id,
                        $device->last_seen,
                        'ALIVE'
                    )
                );
            } catch (\Throwable $e) {
                Log::error(
                    "Heartbeat recovery email failed for client {$device->client_id}: ".$e->getMessage()
                );
            }
        }
    }

    private function sendAlertEmails(array $recipients): void
    {
        if (empty($recipients)) {
            Log::warning('heartbeat:check-alerts skipped sending emails: no active recipients in product_settings.');

            return;
        }

        $downDevices = DB::table('pulse_alerts_heartbeat_monitor')
            ->select('client_id', 'last_seen', 'current_status', 'alert_sent')
            ->whereRaw("last_seen < (NOW() - INTERVAL {$this->alertAfterMinutes} MINUTE)")
            ->where('alert_sent', 0)
            ->get();

        $successfulClientIds = [];

        foreach ($downDevices as $device) {
            try {
                if ((app()->environment('local') || app()->environment('demo')) && (int) env('APP_SEND_EMAIL', 0) !== 1) {
                    Log::info("alerts:check-heartbeat: Skipping down alert for client {$device->client_id} in local/demo environment.");

                    continue;
                }
                Mail::to($recipients)->send(
                    new HeartbeatDownAlertMail(
                        $device->client_id,
                        $device->last_seen,
                        'DOWN'
                    )
                );

                $successfulClientIds[] = $device->client_id;

            } catch (\Throwable $e) {
                Log::error(
                    "Heartbeat alert email failed for client {$device->client_id}: ".$e->getMessage()
                );
            }
        }

        $successfulClientIds = array_values(array_unique($successfulClientIds));

        if (! empty($successfulClientIds)) {
            DB::table('pulse_alerts_heartbeat_monitor')
                ->whereIn('client_id', $successfulClientIds)
                ->update([
                    'alert_sent' => 1,
                    'current_status' => 'DOWN',
                ]);
        }
    }
}
