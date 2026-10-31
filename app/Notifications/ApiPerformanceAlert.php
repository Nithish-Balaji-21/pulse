<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ApiPerformanceAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public array $details)
    {
        $this->onQueue(config('api-performance.queue'));
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $d = $this->details;

        return (new MailMessage)
            ->mailer(config('api-performance.mailer'))
            ->subject('Slow API response: '.$d['endpoint'])
            ->greeting('Slow API response detected')
            ->line('Endpoint: '.$d['endpoint'])
            ->line('Method: '.$d['method'].'; HTTP status: '.$d['status'])
            ->line('Duration: '.$d['duration_ms'].' ms')
            ->line('Environment: '.$d['environment'].'; server: '.$d['server'])
            ->line('Review the related request, queries and outbound calls in Nightwatch.');
    }
}
