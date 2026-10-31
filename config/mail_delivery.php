<?php

return [
    // Shared across Horizon workers. Use a shared, lock-capable cache in production.
    'store' => env('MAIL_DELIVERY_CACHE_STORE', 'redis'),
    // An application pacing choice, not a guaranteed Zoho allowance.
    'interval_seconds' => (int) env('MAIL_SUMMARY_INTERVAL_SECONDS', 60),
    'blocked_seconds' => (int) env('MAIL_BLOCK_COOLDOWN_SECONDS', 3600),
];
