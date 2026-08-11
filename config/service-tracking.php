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
        'model' => env('OOS_EMAIL_PARSING_MODEL', 'gpt-5.4-nano'),
        'reasoning_effort' => env('OOS_EMAIL_PARSING_REASONING_EFFORT', 'minimal'),
        // Output budget for one extraction. Was a hard-coded 3000, which the
        // 2026-08-11 staging run outgrew: identical requests for one 49-line email
        // returned 991, 1081 and 1743 output tokens, and a truncated response
        // surfaced only as an undiagnosable JSON decode failure.
        'max_completion_tokens' => (int) env('OOS_EMAIL_PARSING_MAX_COMPLETION_TOKENS', 6000),
        // Re-asks when the model returns something unusable. One flaky call used to
        // lose a service permanently and fail the whole corpus closeout with it.
        'extraction_attempts' => (int) env('OOS_EMAIL_PARSING_EXTRACTION_ATTEMPTS', 3),
        'review_threshold' => 0.75,
        'auto_import_threshold' => 0.90,
    ],

    'songs' => [
        'sqlite_path' => env('OPENLP_SONGS_DB_PATH'),
    ],

    'song_linking' => [
        // Fuzzy title matching is the linker's last resort and the only rung that can guess
        // wrong; links it makes carry a metadata.song_link audit trail and this kill-switch.
        'fuzzy_enabled' => env('SONG_LINKING_FUZZY_ENABLED', true),
        'fuzzy_threshold' => (float) env('SONG_LINKING_FUZZY_THRESHOLD', 0.90),
        'fuzzy_margin' => (float) env('SONG_LINKING_FUZZY_MARGIN', 0.05),
        'fuzzy_min_probe_length' => (int) env('SONG_LINKING_FUZZY_MIN_PROBE_LENGTH', 10),
    ],
];
