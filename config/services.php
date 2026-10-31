<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'twilio' => [
        'sid' => env('TWILIO_SID'),
        'token' => env('TWILIO_AUTH_TOKEN'),
        'whatsapp_from' => env('TWILIO_WHATSAPP_FROM'),
        'messaging_service_sid' => env('TWILIO_MESSAGING_SERVICE_SID'),
        'content_sid' => env('TWILIO_CONTENT_SID'),
    ],

    // FCM service-account JSON, set per server. The Firebase project ID is read
    // from the file's project_id. A relative path resolves against the project
    // root; when unset, FirebaseService falls back to storage/app/firebase.
    'fcm' => [
        'credentials' => env('FCM_CREDENTIALS'),
    ],

    'scheduler_heartbeat' => [
        'url' => env('SCHEDULER_HEARTBEAT_URL'),
        'timeout' => env('SCHEDULER_HEARTBEAT_TIMEOUT', 5),
    ],

];
