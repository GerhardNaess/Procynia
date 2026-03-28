<?php

return [
    'base_url' => env('DOFFIN_BASE_URL', 'https://betaapi.doffin.no'),
    'search_endpoint' => env('DOFFIN_SEARCH_ENDPOINT', '/public/v2/search'),
    'download_endpoint' => env('DOFFIN_DOWNLOAD_ENDPOINT', '/public/v2/download'),
    'live_search_base_url' => env('DOFFIN_LIVE_SEARCH_BASE_URL', 'https://api.doffin.no/webclient/api/v2/search-api'),
    'live_search_endpoint' => env('DOFFIN_LIVE_SEARCH_ENDPOINT', '/search'),
    'public_notice_url' => env('DOFFIN_PUBLIC_NOTICE_URL', 'https://doffin.no/notices/%s'),
    'api_key' => env('DOFFIN_API_KEY'),
    'user_agent' => env('DOFFIN_USER_AGENT', 'Procynia/1.0'),
    'timeout' => (int) env('DOFFIN_TIMEOUT', 30),
    'batch_limit' => (int) env('DOFFIN_BATCH_LIMIT', 10),
    'relevance' => [
        'weights' => [
            'cpv_match' => 40,
            'keyword_match' => 20,
            'other_rules' => 15,
            'status_bonus' => 10,
            'deadline_bonus' => 10,
            'type_bonus' => 5,
            'learning_adjustment' => 10,
        ],
        'active_statuses' => [],
        'competition_types' => [
            'cn-standard',
        ],
        'competition_subtypes' => [
            '16',
        ],
        'levels' => [
            'high' => 40,
            'medium' => 15,
        ],
    ],
];
