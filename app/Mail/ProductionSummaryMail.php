<?php

namespace App\Mail;

use App\Jobs\Middleware\PaceSummaryMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;

class ProductionSummaryMail extends Mailable implements ShouldQueue
{
    use Queueable;

    // Rate-limit releases count as attempts; the fixed deadline bounds their lifetime.
    public int $tries = 0;

    public int $maxExceptions = 3;

    public $retryUntil;

    public function middleware(): array
    {
        return [new PaceSummaryMail];
    }

    public function backoff(): array
    {
        return [300, 900, 3600];
    }

    public $title;

    public $bodyLines;

    public int $teamId;

    /**
     * Attachment content is base64 encoded because this mailable is queued and
     * Laravel JSON encodes the queue payload; raw binary breaks that encode.
     *
     * Defaults to null so payloads serialized before this property existed
     * still unserialize into a usable mailable.
     *
     * @var array{content_base64: string, filename: string, mime: string}|null
     */
    public ?array $attachment = null;

    public function __construct($title, $bodyLines, int $teamId, ?array $attachment = null)
    {
        $this->retryUntil = now()->addDay();
        $this->teamId = $teamId;
        $this->title = $title;
        $this->bodyLines = $bodyLines;
        $this->attachment = $attachment;
    }

    public function build()
    {
        $mail = $this->subject($this->title)
            ->markdown('pulse::emails.production_summary')
            ->with([
                'title' => $this->title,
                'bodyLines' => $this->bodyLines,
                'preferencesUrl' => $this->preferencesUrl(),
            ]);

        if ($file = $this->attachmentFile()) {
            $mail->attachData($file['content'], $file['filename'], ['mime' => $file['mime']]);
        }

        return $mail;
    }

    /**
     * Decoded attachment, or null when there isn't a usable one.
     *
     * A queued payload outlives the deploy that wrote it, so this accepts both
     * the current base64 shape and the older raw-bytes one: during a rollout,
     * one worker can enqueue a mailable that a worker still running the
     * previous release picks up. The summary body is the point of the email —
     * an attachment we can't read is logged and skipped, never a lost email.
     *
     * @return array{content: string, filename: string, mime: string}|null
     */
    private function attachmentFile(): ?array
    {
        if (empty($this->attachment)) {
            return null;
        }

        $content = match (true) {
            isset($this->attachment['content_base64']) => base64_decode($this->attachment['content_base64'], true),
            isset($this->attachment['content']) => $this->attachment['content'],
            default => null,
        };

        if (! is_string($content) || $content === '' || empty($this->attachment['filename'])) {
            Log::warning('Production summary attachment skipped: unusable payload.', [
                'team_id' => $this->teamId,
                'keys' => array_keys($this->attachment),
            ]);

            return null;
        }

        return [
            'content' => $content,
            'filename' => $this->attachment['filename'],
            'mime' => $this->attachment['mime'] ?? 'application/octet-stream',
        ];
    }

    /**
     * Deep link to the recipient's notification settings for this factory.
     *
     * Recipients include superadmins who are not members of the team; the page
     * itself is team-scoped, so anyone who cannot open it is redirected to a
     * factory they can.
     */
    private function preferencesUrl(): ?string
    {
        try {
            return route('notification-preferences.index', ['team' => $this->teamId]);
        } catch (\Throwable) {
            return null;
        }
    }

    public function getTeamId(): int
    {
        return $this->teamId;
    }
}
