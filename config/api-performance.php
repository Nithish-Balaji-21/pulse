<?php

return [
    'enabled' => env('API_PERFORMANCE_MONITOR_ENABLED', false),
    'warning_ms' => (int) env('API_PERFORMANCE_WARNING_MS', 2000),
    'alert_ms' => (int) env('API_PERFORMANCE_ALERT_MS', 5000),
    'email' => env('API_PERFORMANCE_ALERT_EMAIL'),
    'mailer' => env('API_PERFORMANCE_ALERT_MAILER', env('MAIL_MAILER', 'log')),
    'queue' => 'api-performance-alerts',
];
