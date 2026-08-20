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
        // The semantic compiler path is evaluation-only until the Delivery 7 default flip is
        // approved. `legacy` preserves the current weekly and historic production behaviour.
        'implementation' => env('OOS_EMAIL_PARSING_IMPLEMENTATION', 'legacy'),
        // Dedicated knob (was the shared OPENAI_MODEL) — lowest-stakes structured extraction, so
        // it defaults to the cheapest current model rather than tracking the analysis default.
        'model' => env('OOS_EMAIL_PARSING_MODEL', 'gpt-5.4-nano'),
        'reasoning_effort' => env('OOS_EMAIL_PARSING_REASONING_EFFORT', 'minimal'),
        // Which system prompt the extractor sends. A prompt arm sets this the way an effort arm sets
        // the setting above, so both texts exist in one code state and the difference between two
        // arms stays a declared intervention rather than an edit between runs.
        // See App\Services\Email\OosEmailExtractionPrompt.
        'prompt_variant' => env('OOS_EMAIL_PARSING_PROMPT_VARIANT', 'baseline'),
        // Output budget for one extraction. Was a hard-coded 3000, which the
        // 2026-08-11 staging run outgrew: identical requests for one 49-line email
        // returned 991, 1081 and 1743 output tokens, and a truncated response
        // surfaced only as an undiagnosable JSON decode failure.
        'max_completion_tokens' => (int) env('OOS_EMAIL_PARSING_MAX_COMPLETION_TOKENS', 6000),
        // Extra ceiling per effective reasoning effort, because hidden reasoning tokens are billed
        // against the same budget as the visible JSON. The figure above was measured against
        // `none`/`minimal` output alone, so raising effort without raising the ceiling truncates,
        // retries and reports the stronger setting as the worse one.
        //
        // These are ceilings, not reservations: OpenAI bills tokens actually generated, so headroom
        // the model does not use costs nothing. They are deliberately generous rather than
        // predictive — `service_structure` already runs `medium` against a 16000 budget for
        // comparable JSON, which is the nearest measured anchor this application has.
        'reasoning_token_headroom' => [
            'none' => 0,
            'minimal' => 0,
            'low' => 6000,
            'medium' => 16000,
            'high' => 32000,
        ],
        // Re-asks when the model returns something unusable. One flaky call used to
        // lose a service permanently and fail the whole corpus closeout with it.
        'extraction_attempts' => (int) env('OOS_EMAIL_PARSING_EXTRACTION_ATTEMPTS', 3),
        // Separate from `extraction_attempts` on purpose: that budget answers "the model returned
        // something unusable", this one answers "the request never got through". A rate limit spent
        // from the first budget would consume a semantic re-ask that exists for a different reason.
        // Backoff lives in {@see App\Support\OpenAiTransientFailure}, which waits in seconds for a
        // 429 and milliseconds for a dropped connection.
        'transport_attempts' => (int) env('OOS_EMAIL_PARSING_TRANSPORT_ATTEMPTS', 3),
        'review_threshold' => 0.75,
        'auto_import_threshold' => 0.90,
        'semantic' => [
            'model' => env('OOS_SEMANTIC_ANNOTATION_MODEL', 'gpt-5.6-terra'),
            'reasoning_effort' => env('OOS_SEMANTIC_ANNOTATION_REASONING_EFFORT', 'low'),
            'max_completion_tokens' => (int) env('OOS_SEMANTIC_ANNOTATION_MAX_COMPLETION_TOKENS', 12000),
            'repair_max_completion_tokens' => (int) env('OOS_SEMANTIC_REPAIR_MAX_COMPLETION_TOKENS', 3000),
            'transport_attempts' => (int) env('OOS_SEMANTIC_TRANSPORT_ATTEMPTS', 3),
            'retry_delays_ms' => [100, 500, 1000],
        ],
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
