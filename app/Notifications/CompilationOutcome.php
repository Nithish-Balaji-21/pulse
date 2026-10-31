<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CompilationOutcome extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public array $details)
    {
        $this->onQueue(config('compilation-notifications.queue'));
        $this->afterCommit();
    }

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $d = $this->details;
        $mail = (new MailMessage)
            ->mailer(config('compilation-notifications.mailer'))
            ->subject('Factory '.$d['factory_id'].' compilation '.$d['status'])
            ->greeting('Compilation '.$d['status'])
            ->line('Factory ID: '.$d['factory_id'].'; device ID: '.$d['device_id'])
            ->line('Production date: '.($d['production_date'] ?? 'Multiple dates'))
            ->line('Job: '.$d['job'])
            ->line('Recorded at: '.$d['recorded_at'])
            ->line('Environment: '.$d['environment'].'; server: '.$d['server']);
        $mail->line($d['status'] === 'succeeded'
            ? 'The job returned successfully. This does not independently verify data completeness or downstream notification delivery.'
            : 'The job failed finally or was explicitly failed. Review its execution and exception in Nightwatch.');
        if ($d['exception_class']) {
            $mail->line('Exception type: '.$d['exception_class']);
        }

        return $mail;
    }
}
