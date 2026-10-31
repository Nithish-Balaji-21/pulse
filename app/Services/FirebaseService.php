<?php

namespace App\Services;

use App\Models\UserDeviceToken;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Exception\Messaging\ApiConnectionFailed;
use Kreait\Firebase\Exception\Messaging\InvalidArgument;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Exception\Messaging\QuotaExceeded;
use Kreait\Firebase\Exception\Messaging\ServerError;
use Kreait\Firebase\Exception\Messaging\ServerUnavailable;
use Kreait\Firebase\Exception\MessagingException;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

class FirebaseService
{
    protected $messaging;

    public function __construct()
    {
        $factory = (new Factory)
            ->withServiceAccount(self::credentialsPath());

        $this->messaging = $factory->createMessaging();
    }

    /**
     * Absolute path of the FCM service-account JSON (FCM_CREDENTIALS), or the
     * storage default when the server has not set one.
     */
    public static function credentialsPath(): string
    {
        $path = trim((string) config('services.fcm.credentials'));

        if ($path === '') {
            return storage_path('app/firebase/firebase-credentials.json');
        }

        // Absolute on Linux (/var/...) or Windows (C:\... or C:/...).
        if (preg_match('~^([A-Za-z]:)?[\\\\/]~', $path)) {
            return $path;
        }

        return base_path($path);
    }

    /**
     * Send a notification to each token, returning the tokens whose delivery failed
     * for a transient reason and is therefore worth retrying.
     *
     * @param  list<string>  $deviceTokens
     * @return list<string>
     */
    public function sendNotificationToMultipleTokens(array $deviceTokens, string $title, string $body): array
    {
        Log::info('Sending Firebase notification to:', $deviceTokens);

        $retryableTokens = [];

        foreach ($deviceTokens as $token) {
            try {
                $message = CloudMessage::withTarget('token', $token)
                    ->withNotification(Notification::create($title, $body))
                    ->withHighestPossiblePriority()
                    ->withData([
                        'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                        'title' => $title,
                        'body' => $body,
                    ]);

                $this->messaging->send($message);

            } catch (NotFound $e) {
                // kreait/firebase-php maps FCM's NOT_FOUND and UNREGISTERED errors to the same NotFound exception (HTTP 404).
                Log::warning("Firebase token not found or unregistered, deleting: $token — ".$e->getMessage());
                $this->deleteInvalidToken($token);

            } catch (InvalidArgument $e) {
                Log::warning("Invalid argument for token, deleting: $token - ".$e->getMessage());
                $this->deleteInvalidToken($token);

            } catch (ServerError|ServerUnavailable|QuotaExceeded|ApiConnectionFailed $e) {
                // FCM 429/500/503 and connection failures are transient; the token is still valid.
                Log::warning("Transient Firebase error for token: $token - ".$e->getMessage());
                $retryableTokens[] = $token;

            } catch (MessagingException $e) {
                Log::error("Firebase messaging error for token: $token - ".$e->getMessage());

            } catch (\Throwable $e) {
                Log::error("Unexpected error sending to token: $token - ".$e->getMessage());
            }
        }

        return $retryableTokens;
    }

    private function deleteInvalidToken(string $token): void
    {
        UserDeviceToken::where('fcm_token', $token)->delete();
    }
}
