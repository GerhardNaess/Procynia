<?php

return [
    'health_token' => env('PROCYNIA_HEALTH_TOKEN'),

    'ai' => [
        'usage_guard' => [
            'user_per_minute' => env('AI_RATE_LIMIT_USER_PER_MINUTE', 5),
            'user_decay_seconds' => env('AI_RATE_LIMIT_USER_DECAY_SECONDS', 60),
        ],
    ],

    'backup' => [
        /*
         * Does THIS runtime support the legacy, Compose-based backup mechanism?
         *
         * scripts/backup-production.sh runs `docker compose exec -T postgres pg_dump`, which needs a
         * Docker CLI and a Compose project. Azure Container Apps has neither, so the whole mechanism
         * has to be off there. Azure PostgreSQL Flexible Server provides automated backup and
         * point-in-time restore instead; there is deliberately no Laravel replacement.
         *
         * This is NOT the same question as backup_settings.backup_enabled in the database. That flag
         * answers "has an operator switched backup on?". This one answers "can this runtime execute
         * the Compose mechanism at all?" — and it must win, because a database migrated to Azure can
         * arrive with backup_enabled = true.
         *
         * Default true: an unset value means an existing Compose deployment, whose behaviour must not
         * change. Azure sets it explicitly to false (see infra/main.bicep). filter_var is used rather
         * than a plain cast so that "false", "0" and "off" are all honoured — (bool) "false" is true.
         */
        'legacy_enabled' => filter_var(
            env('PROCYNIA_LEGACY_BACKUP_ENABLED', true),
            FILTER_VALIDATE_BOOLEAN,
        ),
        'directory' => env('BACKUP_DIRECTORY', '/backup/procynia'),
        'rpo_hours' => 1,
        'scheduler_heartbeat_stale_seconds' => 3600,
    ],

    'public' => [
        'contact' => [
            'general_email' => env('PROCYNIA_CONTACT_EMAIL', 'kontakt@procynia.no'),
            'sales_email' => env('PROCYNIA_SALES_EMAIL', 'salg@procynia.no'),
            'support_email' => env('PROCYNIA_SUPPORT_EMAIL', 'support@procynia.no'),
            'privacy_email' => env('PROCYNIA_PRIVACY_EMAIL', 'personvern@procynia.no'),
            'phone' => env('PROCYNIA_PHONE', '+47 00 00 00 00'),
        ],
    ],
];
