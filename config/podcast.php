<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Podcast Owner Information
    |--------------------------------------------------------------------------
    |
    | The owner details that appear in podcast directories like Apple Podcasts.
    | This should be the church or organization responsible for the content.
    |
    */
    'owner' => [
        'name' => env('PODCAST_OWNER_NAME', 'Crockenhill Baptist Church'),
        'email' => env('PODCAST_ADMIN_EMAIL', 'admin@crockenhill.org'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Podcast Author
    |--------------------------------------------------------------------------
    |
    | The author name shown in podcast apps. Typically the church name.
    |
    */
    'author' => env('PODCAST_AUTHOR', 'Crockenhill Baptist Church'),

    /*
    |--------------------------------------------------------------------------
    | Language
    |--------------------------------------------------------------------------
    |
    | The language of the podcast content (RFC 5646 format).
    |
    */
    'language' => 'en-gb',

    /*
    |--------------------------------------------------------------------------
    | iTunes Category
    |--------------------------------------------------------------------------
    |
    | The primary category and subcategory for podcast directories.
    | See: https://podcasters.apple.com/support/1691-apple-podcasts-categories
    |
    */
    'category' => 'Religion & Spirituality',
    'subcategory' => 'Christianity',

    /*
    |--------------------------------------------------------------------------
    | Explicit Content Flag
    |--------------------------------------------------------------------------
    |
    | Whether the podcast contains explicit content. For church sermons,
    | this should always be 'no'.
    |
    */
    'explicit' => 'no',

    /*
    |--------------------------------------------------------------------------
    | Feed Configurations
    |--------------------------------------------------------------------------
    |
    | Separate feed configurations for morning and evening services.
    | Each feed has its own title, description, artwork, and route.
    |
    */
    'feeds' => [
        'morning' => [
            'title' => 'Sunday mornings at Crockenhill Baptist Church',
            'description' => 'Sermons from Sunday mornings at Crockenhill Baptist Church',
            'image' => '/images/podcast/MorningArtwork.jpg',
            'route' => '/christ/sermons/morning',
        ],
        'evening' => [
            'title' => 'Sunday evenings at Crockenhill Baptist Church',
            'description' => 'Sermons from Sunday evenings at Crockenhill Baptist Church',
            'image' => '/images/podcast/EveningArtwork.jpg',
            'route' => '/christ/sermons/evening',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Configuration
    |--------------------------------------------------------------------------
    |
    | Feed caching settings using Laravel's flexible cache.
    | - ttl: Time before cache is considered stale (serves immediately)
    | - stale_ttl: Time before cache is completely expired
    |
    | With flexible caching, stale content is served while fresh content
    | is generated in the background.
    |
    */
    'cache' => [
        'enabled' => env('PODCAST_CACHE_ENABLED', true),
        'ttl' => env('PODCAST_CACHE_TTL', 3600), // 1 hour fresh
        'stale_ttl' => env('PODCAST_CACHE_STALE_TTL', 7200), // 2 hours stale
    ],

    /*
    |--------------------------------------------------------------------------
    | Items Limit
    |--------------------------------------------------------------------------
    |
    | Maximum number of episodes to include in each feed.
    | Apple Podcasts recommends 10-50 episodes for optimal loading.
    |
    */
    'items_limit' => env('PODCAST_ITEMS_LIMIT', 100),
];
