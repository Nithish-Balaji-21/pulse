<?php

namespace App\Console\Commands\Pulse;

use App\Mail\DeviceHealthAlertReportMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class CheckDeviceHealthAlerts extends Command
{
    protected $signature = 'alerts:check-device-health';

    protected $description = 'Send device health alert report for newly inserted rows';

    public function handle()
    {
        try {
            $latestRow = DB::table('pulse_alerts_device_health_log')
                ->orderByDesc('id')
                ->first();

            if (! $latestRow) {
                return self::SUCCESS;
            }

            $lastProcessedId = Cache::get('alerts:device-health:last-id');

            if ($lastProcessedId === null) {
                Cache::forever('alerts:device-health:last-id', $latestRow->id);
                Log::info("alerts:check-device-health initialized with id {$latestRow->id}");

                return self::SUCCESS;
            }

            if ($latestRow->id <= (int) $lastProcessedId) {
                return self::SUCCESS;
            }

            Cache::forever('alerts:device-health:last-id', $latestRow->id);

            if (! $this->hasError($latestRow)) {
                $this->updateAlertTriggered((int) $latestRow->id, 0);

                return self::SUCCESS;
            }

            $recipients = $this->getRecipients();
            if (empty($recipients)) {
                Log::warning('alerts:check-device-health skipped sending emails: no active recipients in product_settings.');

                return self::SUCCESS;
            }

            $issues = $this->collectIssues($latestRow);

            if ((app()->environment('local') || app()->environment('demo')) && (int) env('APP_SEND_EMAIL', 0) !== 1) {
                Log::info('alerts:check-device-health: Skipping email in local/demo environment.');
                $this->updateAlertTriggered((int) $latestRow->id, 0);

                return self::SUCCESS;
            }

            Mail::to($recipients)->send(new DeviceHealthAlertReportMail($latestRow, $issues));
            $this->updateAlertTriggered((int) $latestRow->id, 1);

            Log::warning(
                "alerts:check-device-health alert sent for client {$latestRow->client_id} (row {$latestRow->id})"
            );

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('alerts:check-device-health failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    private function hasError(object $row): bool
    {
        return strtoupper((string) ($row->mfm_status ?? '')) === 'ERROR'
            || strtoupper((string) ($row->queue_status ?? '')) === 'HIGH'
            || strtoupper((string) ($row->uart_status ?? '')) === 'ERROR';
    }

    private function collectIssues(object $row): array
    {
        $issues = [];

        if (strtoupper((string) ($row->mfm_status ?? '')) === 'ERROR') {
            $issues[] = 'MFM status is ERROR';
        }
        if (strtoupper((string) ($row->queue_status ?? '')) === 'HIGH') {
            $issues[] = 'Queue status is HIGH';
        }
        if (strtoupper((string) ($row->uart_status ?? '')) === 'ERROR') {
            $issues[] = 'UART status is ERROR';
        }

        return $issues;
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
            ->map(fn ($email) => trim((string) $email))
            ->map(fn ($email) => trim($email, " \t\n\r\0\x0B\"'[]"))
            ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))
            ->values()
            ->all();
    }

    private function updateAlertTriggered(int $id, int $value): void
    {
        DB::table('pulse_alerts_device_health_log')
            ->where('id', $id)
            ->update(['alert_triggered' => $value]);
    }
}
