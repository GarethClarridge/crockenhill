<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Meeting Patterns
    |--------------------------------------------------------------------------
    |
    | Define patterns to automatically categorize Google Calendar events
    | into meeting types. Events are matched against these patterns to
    | determine their meeting_slug.
    |
    */
    'meeting_patterns' => [
        'sunday-mornings' => [
            'patterns' => ['sunday morning service', 'sunday am', 'morning service'],
            'case_insensitive' => true,
        ],
        'sunday-evenings' => [
            'patterns' => ['sunday evening service', 'sunday pm', 'evening service'],
            'case_insensitive' => true,
        ],
        'prayer-meeting' => [
            'patterns' => ['prayer meeting', 'prayer night', 'church prayer'],
            'case_insensitive' => true,
        ],
        'bible-study' => [
            'patterns' => ['bible study', 'bible study: group 1', 'bible study: group 2', 'bible study: group 3'],
            'case_insensitive' => true,
        ],
        'coffee-cup' => [
            'patterns' => ['coffee cup'],
            'case_insensitive' => true,
        ],
        'baby-talk' => [
            'patterns' => ['baby talk'],
            'case_insensitive' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Sync Window
    |--------------------------------------------------------------------------
    |
    | Configure how far back and forward to sync events from Google Calendar.
    | This helps manage performance and API rate limits.
    |
    */
    'sync_window' => [
        'past_months' => 1,      // How many months back to sync
        'future_years' => 1,     // How many years forward to sync
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for optimizing calendar performance.
    |
    */
    'performance' => [
        'eager_load_limit' => 100,   // Limit for eager loading calendar events
    ],

    /*
    |--------------------------------------------------------------------------
    | Google Calendar Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for Google Calendar API integration.
    |
    */
    'google' => [
        'calendar_id' => env('GOOGLE_CALENDAR_ID'),
        'service_account_credentials_json' => env('GOOGLE_SERVICE_ACCOUNT_CREDENTIALS_JSON'),
    ],
];
