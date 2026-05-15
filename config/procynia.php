<?php

return [
    'health_token' => env('PROCYNIA_HEALTH_TOKEN'),

    'ai' => [
        'usage_guard' => [
            'user_per_minute' => env('AI_RATE_LIMIT_USER_PER_MINUTE', 5),
            'customer_per_hour' => env('AI_RATE_LIMIT_CUSTOMER_PER_HOUR', 50),
            'user_decay_seconds' => env('AI_RATE_LIMIT_USER_DECAY_SECONDS', 60),
            'customer_decay_seconds' => env('AI_RATE_LIMIT_CUSTOMER_DECAY_SECONDS', 3600),
        ],
    ],

    'backup' => [
        'directory' => env('BACKUP_DIRECTORY', '/backup/procynia'),
        'rpo_hours' => 1,
        'scheduler_heartbeat_stale_seconds' => 3600,
    ],
];
