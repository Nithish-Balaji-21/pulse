<?php

namespace App\Jobs;

use App\Services\FirebaseService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendPushNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * How many times a transiently-failed batch of tokens is re-queued before it is dropped.
     */
    private const MAX_RETRY_ROUNDS = 3;

    /**
     * @param  list<string>  $tokens
     */
    public function __construct(
        protected array $tokens,
        protected string $title,
        protected string $body,
        protected int $retryRound = 0,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(FirebaseService $firebaseService): void
    {
        if (empty($this->tokens)) {
            return;
        }

        try {
            $retryableTokens = $firebaseService->sendNotificationToMultipleTokens(
                $this->tokens,
                $this->title,
                $this->body
            );
        } catch (\Throwable $e) {
            Log::error('Push notification job failed: '.$e->getMessage());

            return;
        }

        if (empty($retryableTokens)) {
            return;
        }

        if ($this->retryRound >= self::MAX_RETRY_ROUNDS) {
            Log::error(
                'Giving up on push notification after '.self::MAX_RETRY_ROUNDS.
                ' retries for tokens: '.implode(', ', $retryableTokens)
            );

            return;
        }

        // Only the transiently-failed tokens are re-queued, so already-delivered
        // notifications are not sent a second time.
        self::dispatch($retryableTokens, $this->title, $this->body, $this->retryRound + 1)
            ->delay(now()->addSeconds(30 * (2 ** $this->retryRound)));
    }
}
