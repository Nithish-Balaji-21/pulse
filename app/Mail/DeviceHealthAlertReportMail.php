<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class DeviceHealthAlertReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public object $row;

    public array $issues;

    public function __construct(object $row, array $issues)
    {
        $this->row = $row;
        $this->issues = $issues;
    }

    public function build()
    {
        return $this->subject("Device Health Alert: Client {$this->row->client_id}")
            ->markdown('pulse::emails.device_health_alert_report')
            ->with([
                'row' => $this->row,
                'issues' => $this->issues,
            ]);
    }
}
