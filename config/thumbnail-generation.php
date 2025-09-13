<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Thumbnail Generation Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for automated thumbnail generation from sermon videos.
    | Thumbnails are generated with branded overlays including sermon metadata.
    |
    */
    'enabled' => env('THUMBNAIL_GENERATION_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Storage Configuration
    |--------------------------------------------------------------------------
    |
    | Storage settings for thumbnail files. Uses public disk by default
    | to ensure thumbnails are web-accessible for direct serving.
    |
    */
    'storage' => [
        'disk' => env('THUMBNAIL_STORAGE_DISK', 'public'),
        'path' => env('THUMBNAIL_STORAGE_PATH', 'sermons/thumbnails'),
    ],

    /*
    |--------------------------------------------------------------------------
    | FFmpeg Configuration
    |--------------------------------------------------------------------------
    |
    | FFmpeg settings for video frame extraction. Uses existing FFmpeg
    | installation paths from livestream processing configuration.
    |
    */
    'ffmpeg' => [
        'path' => env('FFMPEG_PATH', '/usr/bin/ffmpeg'),
        'probe_path' => env('FFPROBE_PATH', '/usr/bin/ffprobe'),
        'timeout' => env('THUMBNAIL_FFMPEG_TIMEOUT', 300), // 5 minutes
        'threads' => env('THUMBNAIL_FFMPEG_THREADS', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | Frame Extraction Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for intelligent frame extraction from video files.
    | Extracts frames from optimal positions to represent sermon content.
    |
    */
    'extraction' => [
        'start_offset' => env('THUMBNAIL_START_OFFSET', 60), // seconds into video
        'end_buffer' => env('THUMBNAIL_END_BUFFER', 60),     // seconds from end to avoid
        'fallback_position' => env('THUMBNAIL_FALLBACK_POSITION', 0.5), // midpoint for short videos
        'min_video_duration' => env('THUMBNAIL_MIN_DURATION', 120), // minimum video length in seconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Thumbnail Sizes Configuration
    |--------------------------------------------------------------------------
    |
    | Multiple thumbnail sizes for different use cases.
    | Web size for desktop displays, mobile size for responsive design.
    |
    */
    'sizes' => [
        'web' => [
            'width' => env('THUMBNAIL_WEB_WIDTH', 1280),
            'height' => env('THUMBNAIL_WEB_HEIGHT', 720),
            'quality' => env('THUMBNAIL_WEB_QUALITY', 85),
        ],
        'mobile' => [
            'width' => env('THUMBNAIL_MOBILE_WIDTH', 640),
            'height' => env('THUMBNAIL_MOBILE_HEIGHT', 360),
            'quality' => env('THUMBNAIL_MOBILE_QUALITY', 80),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Overlay Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for branded overlay generation with sermon metadata.
    | Includes church branding, sermon title, and service date.
    |
    */
    'overlay' => [
        'brand_image' => env('THUMBNAIL_BRAND_IMAGE', 'images/BrandOverlay.png'),
        'brand_position' => env('THUMBNAIL_BRAND_POSITION', 'bottom-right'),
        'brand_margin' => env('THUMBNAIL_BRAND_MARGIN', 20), // pixels from edge
        
        'font' => [
            'family' => env('THUMBNAIL_FONT_FAMILY', 'Oswald'),
            'title_size' => env('THUMBNAIL_TITLE_SIZE', 48),
            'date_size' => env('THUMBNAIL_DATE_SIZE', 32),
            'color' => env('THUMBNAIL_FONT_COLOR', '#000000'),
            'stroke_color' => env('THUMBNAIL_STROKE_COLOR', '#FFFFFF'),
            'stroke_width' => env('THUMBNAIL_STROKE_WIDTH', 2),
        ],
        
        'background' => [
            'color' => env('THUMBNAIL_BG_COLOR', '#FFFFFF'),
            'opacity' => env('THUMBNAIL_BG_OPACITY', 0.8),
            'padding' => env('THUMBNAIL_BG_PADDING', 15), // pixels
            'border_radius' => env('THUMBNAIL_BG_RADIUS', 8), // pixels
        ],
        
        'positioning' => [
            'title_x' => env('THUMBNAIL_TITLE_X', 50), // pixels from left
            'title_y' => env('THUMBNAIL_TITLE_Y', 100), // pixels from top
            'date_x' => env('THUMBNAIL_DATE_X', 50), // pixels from left
            'date_y' => env('THUMBNAIL_DATE_Y', 160), // pixels from top
            'max_title_width' => env('THUMBNAIL_MAX_TITLE_WIDTH', 800), // pixels
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    |
    | Queue settings for thumbnail generation jobs. Uses separate queue
    | to prevent blocking critical processing operations.
    |
    */
    'queue' => [
        'name' => env('THUMBNAIL_QUEUE_NAME', 'thumbnails'),
        'connection' => env('THUMBNAIL_QUEUE_CONNECTION', 'database'),
        'timeout' => env('THUMBNAIL_QUEUE_TIMEOUT', 300), // 5 minutes
        'tries' => env('THUMBNAIL_QUEUE_TRIES', 1), // Single attempt - don't retry failures
        'retry_delay' => env('THUMBNAIL_RETRY_DELAY', 60), // seconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Processing Configuration
    |--------------------------------------------------------------------------
    |
    | General processing settings and limits for thumbnail generation.
    |
    */
    'processing' => [
        'max_concurrent_jobs' => env('THUMBNAIL_MAX_CONCURRENT', 3),
        'memory_limit' => env('THUMBNAIL_MEMORY_LIMIT', '512M'),
        'temp_disk' => env('THUMBNAIL_TEMP_DISK', 'local'),
        'temp_path' => env('THUMBNAIL_TEMP_PATH', 'temp/thumbnails'),
        'cleanup_temp_files' => env('THUMBNAIL_CLEANUP_TEMP', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Fallback Configuration
    |--------------------------------------------------------------------------
    |
    | Fallback settings for when thumbnail generation fails or
    | when no suitable frame can be extracted from the video.
    |
    */
    'fallback' => [
        'default_image' => env('THUMBNAIL_DEFAULT_IMAGE', 'images/default-sermon-thumbnail.jpg'),
        'retry_attempts' => env('THUMBNAIL_RETRY_ATTEMPTS', 3),
        'use_default_on_failure' => env('THUMBNAIL_USE_DEFAULT', false),
        'skip_on_failure' => env('THUMBNAIL_SKIP_ON_FAILURE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Caching Configuration
    |--------------------------------------------------------------------------
    |
    | HTTP caching settings for thumbnail serving and API responses.
    |
    */
    'caching' => [
        'enabled' => env('THUMBNAIL_CACHING_ENABLED', true),
        'max_age' => env('THUMBNAIL_CACHE_MAX_AGE', 86400), // 24 hours
        'etag_enabled' => env('THUMBNAIL_ETAG_ENABLED', true),
        'last_modified_enabled' => env('THUMBNAIL_LAST_MODIFIED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Social Media Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for social media sharing optimization including
    | Open Graph meta tags and aspect ratio handling.
    |
    */
    'social_media' => [
        'og_image_width' => env('THUMBNAIL_OG_WIDTH', 1200),
        'og_image_height' => env('THUMBNAIL_OG_HEIGHT', 630),
        'twitter_card_type' => env('THUMBNAIL_TWITTER_CARD', 'summary_large_image'),
        'optimize_for_sharing' => env('THUMBNAIL_OPTIMIZE_SHARING', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging Configuration
    |--------------------------------------------------------------------------
    |
    | Logging settings for thumbnail generation process.
    | Detailed logging can be disabled in production for performance.
    |
    */
    'logging' => [
        'enabled' => env('THUMBNAIL_LOGGING_ENABLED', true),
        'detailed' => env('THUMBNAIL_DETAILED_LOGGING', false),
        'log_ffmpeg_output' => env('THUMBNAIL_LOG_FFMPEG', false),
        'performance_monitoring' => env('THUMBNAIL_PERFORMANCE_MONITORING', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Validation Configuration
    |--------------------------------------------------------------------------
    |
    | Validation settings for input files and generated thumbnails.
    |
    */
    'validation' => [
        'supported_video_formats' => ['mp4', 'mov', 'avi', 'mkv', 'webm'],
        'max_video_file_size' => env('THUMBNAIL_MAX_VIDEO_SIZE', 2147483648), // 2GB
        'min_video_resolution' => [
            'width' => env('THUMBNAIL_MIN_WIDTH', 640),
            'height' => env('THUMBNAIL_MIN_HEIGHT', 360),
        ],
        'validate_brand_image' => env('THUMBNAIL_VALIDATE_BRAND', true),
        'validate_fonts' => env('THUMBNAIL_VALIDATE_FONTS', true),
    ],
];