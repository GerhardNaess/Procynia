<?php

return [
    /*
     * TED's public Search API. No key is required — the endpoint is open, which is why this
     * adapter needs no credentials and no admin toggle to exist.
     *
     * Verified against the live API on 27 September 2026: POST /v3/notices/search, with `query`
     * and `fields` both required, and a response of
     * {notices: [...], totalNoticeCount: int, iterationNextToken: ?string, timedOut: bool}.
     */
    'search_url' => env('TED_SEARCH_URL', 'https://api.ted.europa.eu/v3/notices/search'),

    /*
     * Where a person goes to read the notice itself. %s is TED's publication number, which is the
     * external id Procynia stores.
     */
    'public_notice_url' => env('TED_PUBLIC_NOTICE_URL', 'https://ted.europa.eu/en/notice/-/detail/%s'),

    'timeout' => (int) env('TED_TIMEOUT', 30),

    /*
     * TED returns nothing unless asked, and rejects the whole request for one unknown field name.
     * These are the nine Procynia actually reads; every one was confirmed accepted by the live
     * API. `links` comes back whether or not it is asked for.
     *
     * Deliberately absent: notice-status. TED has no such field — the API rejects it — so the
     * closest signal about whether an opportunity is live is notice-type, and TedSourceAdapter
     * says so rather than inventing a status.
     */
    'search_fields' => [
        'publication-number',
        'notice-title',
        'description-proc',
        'buyer-name',
        'publication-date',
        'deadline-receipt-request',
        'classification-cpv',
        'notice-type',
        'links',
    ],
];
