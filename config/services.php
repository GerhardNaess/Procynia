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
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'pdftotext' => [
        'binary' => env('PDFTOTEXT_BINARY'),
    ],

    'pdftohtml' => [
        'binary' => env('PDFTOHTML_BINARY'),
    ],

    'pdfimages' => [
        'binary' => env('PDFIMAGES_BINARY'),
    ],

    'pdfinfo' => [
        'binary' => env('PDFINFO_BINARY'),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'model' => env('OPENAI_MODEL', 'gpt-4.1-mini'),
        'requirement_relevance_model' => env(
            'OPENAI_REQUIREMENT_RELEVANCE_MODEL',
            env('OPENAI_REQUIREMENT_EXTRACTION_MODEL', env('OPENAI_MODEL', 'gpt-4.1-mini')),
        ),
        'requirement_extraction_model' => env(
            'OPENAI_REQUIREMENT_EXTRACTION_MODEL',
            env('OPENAI_MODEL', 'gpt-4.1-mini'),
        ),
        'requirement_grounding_judge_model' => env(
            'OPENAI_REQUIREMENT_GROUNDING_JUDGE_MODEL',
            env('OPENAI_REQUIREMENT_EXTRACTION_MODEL', env('OPENAI_MODEL', 'gpt-4.1-mini')),
        ),
        'requirement_answer_model' => env(
            'OPENAI_REQUIREMENT_ANSWER_MODEL',
            'gpt-5',
        ),
        'embedding_model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small'),
        'provider_key' => env('OPENAI_PROVIDER_KEY', 'openai'),
        'deployment_name' => env('OPENAI_DEPLOYMENT_NAME'),
        'provider_region' => env('OPENAI_PROVIDER_REGION'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'uptime_kuma' => [
        'url' => env('UPTIME_KUMA_URL', ''),
    ],

    'enterprise_wiki' => [
        'ai_enabled' => (bool) env('ENTERPRISE_WIKI_AI_ENABLED', false),

        // v0.10 (docs/enterprise-llm-wiki-plan.md, "Arkitekturnotat — v0.10"): a sensible run-wide
        // ceiling on how many NEW claims one applied run's claim extraction may persist, so claims
        // stay "few and material" rather than growing unbounded with page count. Default 60 =
        // 3x WikiPageClaimExtractionAiClient::MAX_CLAIMS (20/page): the two mandatory pages
        // (article + summary), which existing generation order always processes first, fit
        // comfortably within it, leaving meaningful headroom for concept/entity pages without
        // reaching the run-34/run-38-style overgeneration this cap exists to prevent. Reaching the
        // cap never fails the run — remaining pages simply complete their extraction step with
        // zero new claims (see EnterpriseWikiExtractPageClaimsService::extract()).
        'max_new_claims_per_run' => (int) env('ENTERPRISE_WIKI_MAX_NEW_CLAIMS_PER_RUN', 60),
    ],

];
