<?php

namespace App\Notifications;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CronMissedNotification extends Notification
{
    use Queueable;

    protected $missedCrons;

    protected $failedJobs;

    protected $unprocessedTeams;

    protected $date;

    /**
     * Create a new notification instance.
     *
     * @param  string  $jobName
     * @param  string|\DateTimeInterface  $expectedAt
     */
    public function __construct($missedCrons, $failedJobs, $date, $unprocessedTeams = [])
    {
        $this->missedCrons = $missedCrons;    // array of ['job_name', 'date']
        $this->failedJobs = $failedJobs;      // grouped by team_name => [job names]
        $this->unprocessedTeams = $unprocessedTeams;  // grouped by team_name => ['job_name' => ..., 'run_date' => ...]
        $this->date = $date instanceof \DateTimeInterface
                            ? $date->format('Y-m-d')
                            : (string) $date;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return MailMessage
     */
    public function toMail($notifiable)
    {
        $formattedDate = Carbon::parse($this->date)->format('M j, Y');

        $mail = (new MailMessage)
            ->subject('ERROR: System Errors Detected - '.date('M d, Y (D)', strtotime($this->date)))
            ->greeting('ERROR: System Errors Detected - '.date('M d, Y (D)', strtotime($this->date)))
            ->line("The system detected one or more issues on {$formattedDate}.")
            ->line('Please review the logs and fix the issue.');

        // Missed Crons
        if (! empty($this->missedCrons)) {
            $mail->line('**❌ Crons missed:**');
            foreach ($this->missedCrons as $cron) {
                $mail->line("   • {$cron['job_name']}");
            }
        }

        // Failed Jobs
        if (! empty($this->failedJobs)) {
            $mail->line('**⚠ Crons failed:**');
            foreach ($this->failedJobs as $teamName => $jobNames) {
                $mail->line("**{$teamName}:**");
                foreach ($jobNames as $jobName) {
                    $mail->line("   • {$jobName}");
                }
            }
        }

        // Dispatched but never completed
        if (! empty($this->unprocessedTeams)) {
            $mail->line('**⛔ Teams dispatched but never processed:**');
            foreach ($this->unprocessedTeams as $teamName => $runs) {
                $mail->line("**{$teamName}:**");
                foreach ($runs as $run) {
                    $mail->line("   • {$run['job_name']} — shift day ".Carbon::parse($run['run_date'])->format('M j, Y'));
                }
            }
        }

        return $mail;
    }
}
