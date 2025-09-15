<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Media Processing Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the refactored media processing architecture.
    | This replaces scattered configuration across multiple files with
    | a unified configuration system using the strategy pattern.
    |
    */

    'strategies' => [
        'audio' => [
            'class' => \App\Services\Strategies\AudioProcessingStrategy::class,
            'max_file_size' => 100 * 1024 * 1024, // 100MB
            'allowed_extensions' => ['mp3', 'wav', 'm4a', 'mp4'],
            'mime_types' => ['audio/mpeg', 'audio/wav', 'audio/mp4', 'audio/x-m4a'],
            'queue' => 'audio-processing',
            'timeout' => 1800, // 30 minutes
            'validation' => [
                'min_duration' => 60, // 1 minute
                'max_duration' => 7200, // 2 hours
            ],
        ],

        'sermon_video' => [
            'class' => \App\Services\Strategies\DirectVideoProcessingStrategy::class,
            'max_file_size' => 100 * 1024 * 1024, // 100MB
            'allowed_extensions' => ['mp4', 'mov', 'avi', 'mkv', 'webm'],
            'mime_types' => ['video/mp4', 'video/quicktime', 'video/x-msvideo', 'video/x-matroska', 'video/webm'],
            'queue' => 'video-processing',
            'timeout' => 3600, // 1 hour
            'validation' => [
                'min_duration' => 300, // 5 minutes
                'max_duration' => 7200, // 2 hours
                'max_resolution' => '1920x1080',
            ],
            'features' => [
                'generates_thumbnail' => true,
                'extracts_audio' => true,
            ],
        ],

        'livestream' => [
            'class' => \App\Services\Strategies\LivestreamProcessingStrategy::class,
            'max_file_size' => 2 * 1024 * 1024 * 1024, // 2GB
            'allowed_extensions' => ['mp4', 'mov', 'avi', 'mkv', 'webm'],
            'mime_types' => ['video/mp4', 'video/quicktime', 'video/x-msvideo', 'video/x-matroska', 'video/webm'],
            'queue' => 'livestream-processing',
            'timeout' => 7200, // 2 hours
            'validation' => [
                'min_duration' => 1800, // 30 minutes
                'max_duration' => 14400, // 4 hours
            ],
            'features' => [
                'requires_segmentation' => true,
                'generates_thumbnail' => true,
                'extracts_sermon' => true,
                'supports_streaming' => true,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Processing Pipeline Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for job chains and processing pipelines.
    | Defines the steps and order for different processing types.
    |
    */

    'pipelines' => [
        'audio' => [
            'jobs' => [
                \App\Jobs\ValidateAudioFile::class,
                \App\Jobs\TranscribeAudio::class,
                \App\Jobs\ProcessTranscriptWithAI::class,
                \App\Jobs\CreateSermonRecord::class,
                \App\Jobs\SendCompletionNotification::class,
            ],
            'retry_attempts' => 3,
            'retry_delay' => 60, // seconds
        ],

        'sermon_video' => [
            'jobs' => [
                \App\Jobs\ValidateVideoFile::class,
                \App\Jobs\ExtractAudioFromVideo::class,
                \App\Jobs\TranscribeAudio::class,
                \App\Jobs\ProcessTranscriptWithAI::class,
                \App\Jobs\CreateSermonRecord::class,
                \App\Jobs\GenerateThumbnail::class,
                \App\Jobs\SendCompletionNotification::class,
            ],
            'retry_attempts' => 3,
            'retry_delay' => 120, // seconds
        ],

        'livestream' => [
            'jobs' => [
                \App\Jobs\GenerateRmsLog::class,
                \App\Jobs\AnalyzeSegments::class,
                \App\Jobs\ExtractSermon::class,
                \App\Jobs\SubmitToProcessing::class,
                \App\Jobs\GenerateThumbnail::class,
                \App\Jobs\CleanupTemporaryFiles::class,
            ],
            'retry_attempts' => 2,
            'retry_delay' => 300, // seconds
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Error Handling Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the ProcessingExceptionHandler and error recovery.
    |
    */

    'error_handling' => [
        'max_retries' => 3,
        'retry_delay' => 60, // seconds
        'enable_notifications' => true,
        'notification_email' => env('PROCESSING_ADMIN_EMAIL', 'admin@example.com'),
        'log_level' => 'error',
        'graceful_degradation' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Health Monitoring Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for processing health checks and monitoring.
    |
    */

    'health_monitoring' => [
        'enabled' => true,
        'check_interval' => 300, // 5 minutes
        'thresholds' => [
            'success_rate' => 80, // percentage
            'max_stuck_jobs' => 5,
            'max_pending_jobs' => 20,
            'max_failed_jobs_per_day' => 10,
        ],
        'alerts' => [
            'email' => env('PROCESSING_ADMIN_EMAIL', 'admin@example.com'),
            'slack_webhook' => env('PROCESSING_SLACK_WEBHOOK'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for file storage and cleanup policies.
    |
    */

    'storage' => [
        'temp_disk' => env('PROCESSING_TEMP_DISK', 'local'),
        'permanent_disk' => env('PROCESSING_PERMANENT_DISK', 'public'),
        'cleanup_policy' => [
            'temp_files_retention' => 24, // hours
            'failed_processing_retention' => 168, // hours (7 days)
            'auto_cleanup_enabled' => true,
        ],
        'paths' => [
            'audio' => 'sermons/audio',
            'video' => 'sermons/video',
            'thumbnails' => 'sermons/thumbnails',
            'temp' => 'temp/processing',
        ],
    ],
];
