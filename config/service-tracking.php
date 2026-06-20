<?php

return [
    'enabled' => env('SERVICE_TRACKING_ENABLED', true),

    'confidence' => [
        'review_below' => 0.60,
    ],

    'upload' => [
        'max_size_kb' => 600 * 1024,
        'max_zip_entries' => 100,
        'max_osj_decompressed_bytes' => 10 * 1024 * 1024,
        'max_expansion_ratio' => 1000,
    ],

    'mailgun' => [
        'signing_key' => env('MAILGUN_SIGNING_KEY'),
        'timestamp_tolerance_seconds' => (int) env('MAILGUN_TIMESTAMP_TOLERANCE_SECONDS', 300),
    ],

    'email_parsing' => [
        // Dedicated knob (was the shared OPENAI_MODEL) — lowest-stakes structured extraction, so
        // it defaults to the cheapest current model rather than tracking the analysis default.
        'model' => env('OOS_EMAIL_PARSING_MODEL', 'gpt-4.1-nano'),
        'review_threshold' => 0.75,
        'auto_import_threshold' => 0.90,
    ],

    'songs' => [
        'sqlite_path' => env('OPENLP_SONGS_DB_PATH'),
    ],
];
