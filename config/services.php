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

];
