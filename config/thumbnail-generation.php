<?php

return [
    'enabled' => env('THUMBNAIL_GENERATION_ENABLED', true),

    'storage' => [
        'disk' => env('THUMBNAIL_STORAGE_DISK', 'public'),
        'path' => env('THUMBNAIL_STORAGE_PATH', 'sermons/thumbnails'),
    ],

    'ffmpeg' => [
        'path' => env('FFMPEG_PATH', '/usr/bin/ffmpeg'),
        'probe_path' => env('FFPROBE_PATH', '/usr/bin/ffprobe'),
        'timeout' => env('THUMBNAIL_FFMPEG_TIMEOUT', 300),
        'threads' => env('THUMBNAIL_FFMPEG_THREADS', 2),
    ],

    'extraction' => [
        'start_offset' => env('THUMBNAIL_START_OFFSET', 300),
        'end_buffer' => env('THUMBNAIL_END_BUFFER', 60),
        'fallback_position' => env('THUMBNAIL_FALLBACK_POSITION', 0.5),
        'min_video_duration' => env('THUMBNAIL_MIN_DURATION', 420),
    ],

    'queue' => [
        'name' => env('THUMBNAIL_QUEUE_NAME', 'thumbnails'),
        'connection' => env('THUMBNAIL_QUEUE_CONNECTION', 'database'),
        'timeout' => env('THUMBNAIL_QUEUE_TIMEOUT', 300),
        'tries' => env('THUMBNAIL_QUEUE_TRIES', 1),
        'retry_delay' => env('THUMBNAIL_RETRY_DELAY', 60),
    ],

    'processing' => [
        'max_concurrent_jobs' => env('THUMBNAIL_MAX_CONCURRENT', 3),
        'memory_limit' => env('THUMBNAIL_MEMORY_LIMIT', '512M'),
        'temp_disk' => env('THUMBNAIL_TEMP_DISK', 'local'),
        'temp_path' => env('THUMBNAIL_TEMP_PATH', 'temp/thumbnails'),
        'cleanup_temp_files' => env('THUMBNAIL_CLEANUP_TEMP', true),
    ],

];
