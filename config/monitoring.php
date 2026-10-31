<?php

return [
    'failed_job_alerts' => [
        'enabled' => env('FAILED_JOB_ALERTS_ENABLED', true),
        // Prefer dedicated alert recipients, then Horizon operators. If both
        // are empty, SendFailedJobAlert falls back to active superadmins.
        'recipients' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('FAILED_JOB_ALERT_RECIPIENTS', env('HORIZON_RECIPIENTS', '')))
        ))),
    ],
];
