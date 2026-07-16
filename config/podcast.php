<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Podcast Enabled
    |--------------------------------------------------------------------------
    |
    | Whether the podcast feeds are publicly available.
    |
    */
    'enabled' => env('PODCAST_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Feed Configurations
    |--------------------------------------------------------------------------
    |
    | Separate feed configurations for morning and evening services.
    |
    */
    'feeds' => [
        'morning' => [
            'title' => 'Sunday mornings at Crockenhill Baptist Church',
            'description' => 'Sermons from Sunday mornings at Crockenhill Baptist Church',
            'image' => '/images/podcast/MorningArtwork.jpg',
            'route' => '/christ/sermons/morning',
            // Permanent UUID for Podcast 2.0 - DO NOT CHANGE once published
            'podcast_guid' => 'cbc-morning-sermons-a1b2c3d4-e5f6-7890-abcd-ef1234567890',
        ],
        'evening' => [
            'title' => 'Sunday evenings at Crockenhill Baptist Church',
            'description' => 'Sermons from Sunday evenings at Crockenhill Baptist Church',
            'image' => '/images/podcast/EveningArtwork.jpg',
            'route' => '/christ/sermons/evening',
            // Permanent UUID for Podcast 2.0 - DO NOT CHANGE once published
            'podcast_guid' => 'cbc-evening-sermons-f9e8d7c6-b5a4-3210-fedc-ba0987654321',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Static Podcast Metadata
    |--------------------------------------------------------------------------
    |
    | Church identity fields used in podcast directory listings.
    | These never vary by environment.
    |
    */
    'owner' => [
        'name' => 'Crockenhill Baptist Church',
        'email' => 'admin@crockenhill.org',
    ],
    'author' => 'Crockenhill Baptist Church',
    'language' => 'en-gb',
    'category' => 'Religion & Spirituality',
    'subcategory' => 'Christianity',
    'explicit' => 'no',

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
        'ttl' => env('PODCAST_CACHE_TTL', 300),
        'stale_ttl' => env('PODCAST_CACHE_STALE_TTL', 86400),
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
