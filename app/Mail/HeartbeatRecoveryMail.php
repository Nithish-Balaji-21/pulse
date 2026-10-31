<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class HeartbeatRecoveryMail extends Mailable
{
    use Queueable, SerializesModels;

    public $clientId;

    public $lastSeen;

    public $currentStatus;

    public $checkedAt;

    public function __construct($clientId, $lastSeen, $currentStatus = 'ALIVE', $checkedAt = null)
    {
        $this->clientId = $clientId;
        $this->lastSeen = $lastSeen;
        $this->currentStatus = $currentStatus;
        $this->checkedAt = $checkedAt ?? now()->toDateTimeString();
    }

    public function build()
    {
        return $this->subject("Heartbeat Recovery: Client {$this->clientId} is {$this->currentStatus}")
            ->markdown('pulse::emails.heartbeat_recovery_alert')
            ->with([
                'clientId' => $this->clientId,
                'lastSeen' => $this->lastSeen,
                'currentStatus' => $this->currentStatus,
                'checkedAt' => $this->checkedAt,
            ]);
    }
}
