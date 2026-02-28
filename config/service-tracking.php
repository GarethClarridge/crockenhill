<?php

return [
    'enabled' => env('SERVICE_TRACKING_ENABLED', true),

    'confidence' => [
        'review_below' => 0.60,
    ],

    'upload' => [
        'max_size_kb' => 600 * 1024,
    ],

    'songs' => [
        'sqlite_path' => env('OPENLP_SONGS_DB_PATH'),
    ],
];
