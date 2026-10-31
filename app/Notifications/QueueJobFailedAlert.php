<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class QueueJobFailedAlert extends Notification
{
    /**
     * This notification is intentionally synchronous: a failed queue must not
     * be relied upon to deliver its own failure alert.
     *
     * @param  array{job: string, job_id: string, connection: string, queue: string, attempts: int, exception: string, occurred_at: string, environment: string, server: string}  $details
     */
    public function __construct(public array $details) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $d = $this->details;

        return (new MailMessage)
            ->subject('[ACTION REQUIRED] Queue job failed: '.$d['job'])
            ->greeting('A queue job has failed permanently')
            ->line('Job: '.$d['job'])
            ->line('Job ID: '.$d['job_id'])
            ->line('Connection / queue: '.$d['connection'].' / '.$d['queue'])
            ->line('Attempts: '.$d['attempts'])
            ->line('Exception: '.$d['exception'])
            ->line('Occurred at: '.$d['occurred_at'])
            ->line('Environment: '.$d['environment'].'; server: '.$d['server'])
            ->line('Review the failed-job entry in Horizon and the exception trace in Nightwatch.');
    }
}
