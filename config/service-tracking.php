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
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'review_threshold' => 0.75,
        'auto_import_threshold' => 0.90,
    ],

    'songs' => [
        'sqlite_path' => env('OPENLP_SONGS_DB_PATH'),
    ],
];
