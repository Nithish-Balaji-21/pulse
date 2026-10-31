<?php

return [
    'enabled' => env('COMPILATION_NOTIFICATIONS_ENABLED', false),
    'email' => env('COMPILATION_NOTIFICATIONS_EMAIL'),
    'mailer' => env('COMPILATION_NOTIFICATIONS_MAILER', env('MAIL_MAILER', 'log')),
    'queue' => 'compilation-alerts',
];
