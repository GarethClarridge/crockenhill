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

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'signing_key' => env('MAILGUN_SIGNING_KEY'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'google' => [
        'calendar_id' => env('GOOGLE_CALENDAR_ID'),
        'service_account_credentials_json' => env('GOOGLE_SERVICE_ACCOUNT_CREDENTIALS_JSON'),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'organization' => env('OPENAI_ORGANIZATION'),
    ],

    'google_analytics' => [
        'measurement_id' => env('GOOGLE_ANALYTICS_ID'),
    ],

    'api_bible' => [
        'enabled' => env('API_BIBLE_ENABLED', false),
        'key' => env('API_BIBLE_KEY'),
        'base_url' => env('API_BIBLE_BASE_URL', 'https://api.scripture.api.bible/v1'),
        'default_bible_id' => env('API_BIBLE_DEFAULT_BIBLE_ID'),
        'fums_version' => env('API_BIBLE_FUMS_VERSION', '3'),
        'timeout_seconds' => (int) env('API_BIBLE_TIMEOUT_SECONDS', 10),
        'max_retries' => (int) env('API_BIBLE_MAX_RETRIES', 3),
        'refresh_after_days' => (int) env('API_BIBLE_REFRESH_AFTER_DAYS', 28),
        'daily_budget' => (int) env('API_BIBLE_DAILY_BUDGET', 5000),
    ],

    'pixian' => [
        'enabled' => env('PIXIAN_ENABLED', false),
        'api_id' => env('PIXIAN_API_ID'),
        'api_secret' => env('PIXIAN_API_SECRET'),
        'base_url' => env('PIXIAN_BASE_URL', 'https://api.pixian.ai/api/v2'),
        'timeout_seconds' => (int) env('PIXIAN_TIMEOUT_SECONDS', 180),
        'test' => env('PIXIAN_TEST', false),
    ],

];
