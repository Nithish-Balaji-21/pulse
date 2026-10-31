<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class OTPService
{
    public function sendOTP(string $username, string $method = 'whatsapp'): bool
    {
        $otp = rand(100000, 999999);
        Cache::put('otp_'.$username, $otp, now()->addMinutes(10));
        Log::info("Sending OTP {$otp} to {$method}: {$username}");

        if ($method === 'whatsapp') {
            return $this->sendWhatsappOtp($username, $otp);
        }

        return $this->sendEmailOtp($username, $otp);
    }

    public function sendWhatsappOtp(string $phoneNumber, int $otp): bool
    {
        try {
            $twilioSid = config('services.twilio.sid');
            $twilioAuthToken = config('services.twilio.token');
            $messagingServiceSid = config('services.twilio.messaging_service_sid');
            $twilioContentSid = config('services.twilio.content_sid');

            $url = "https://api.twilio.com/2010-04-01/Accounts/{$twilioSid}/Messages.json";

            $response = Http::withBasicAuth($twilioSid, $twilioAuthToken)
                ->asForm()
                ->post($url, [
                    'To' => 'whatsapp:+91'.ltrim($phoneNumber, '+'),
                    'From' => 'whatsapp:+918015203494',
                    'ContentSid' => $twilioContentSid,
                    'ContentVariables' => json_encode([
                        '1' => (string) $otp,
                    ]),
                ]);

            Log::info('OTP sent via WhatsApp template', [
                'otp' => $otp,
                'response' => $response->body(),
            ]);

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('WhatsApp OTP send failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    public function sendEmailOtp(string $email, int $otp): bool
    {
        try {
            $htmlContent = view('emails.otp', ['otp' => $otp])->render();

            Mail::html($htmlContent, function ($message) use ($email) {
                $message->to($email)
                    ->subject('OTP for Login on CoiioT portal');
            });

            Log::info('OTP sent via Email', [
                'otp' => $otp,
                'to' => $email,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Email OTP send failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    public function verifyOTP(string $phoneNumber, int $otp): bool
    {
        $storedOtp = Cache::get('otp_'.$phoneNumber);
        // Cache::get() returns numeric values as raw strings on the redis driver
        // (Illuminate\Cache\RedisStore::unserialize), so cast before comparing.
        if ($storedOtp && (int) $storedOtp === $otp) {
            Cache::forget('otp_'.$phoneNumber);

            return true;
        }

        return false;
    }
}
