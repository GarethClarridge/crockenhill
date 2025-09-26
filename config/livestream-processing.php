<?php

return [
    /*
    |--------------------------------------------------------------------------
    | RMS Threshold Configuration
    |--------------------------------------------------------------------------
    |
    | The RMS (Root Mean Square) threshold used to distinguish between
    | loud sections (music/singing) and quiet sections (speech/prayer).
    | Sections above this threshold are classified as "song",
    | sections below are classified as "speech".
    |
    | When adaptive thresholds are enabled, the fixed threshold serves
    | as a fallback if adaptive calculation fails.
    |
    */
    'rms_threshold' => env('LIVESTREAM_RMS_THRESHOLD', -45.0),

    /*
    |--------------------------------------------------------------------------
    | Adaptive Threshold Configuration
    |--------------------------------------------------------------------------
    |
    | Adaptive thresholds calculate speech/music boundaries based on each
    | file's RMS distribution rather than using fixed values.
    |
    */
    'adaptive_thresholds' => [
        'enabled' => env('LIVESTREAM_ADAPTIVE_THRESHOLDS_ENABLED', true),
        'speech_percentile' => env('LIVESTREAM_SPEECH_PERCENTILE', 30), // 30th percentile for speech threshold
        'fallback_enabled' => env('LIVESTREAM_ADAPTIVE_FALLBACK_ENABLED', true),
        'min_threshold' => env('LIVESTREAM_MIN_THRESHOLD', -80.0), // Don't go below this
        'max_threshold' => env('LIVESTREAM_MAX_THRESHOLD', -20.0), // Don't go above this
        'min_sample_count' => env('LIVESTREAM_MIN_SAMPLE_COUNT', 1000), // Minimum RMS samples needed
    ],

    /*
    |--------------------------------------------------------------------------
    | Section Duration Limits
    |--------------------------------------------------------------------------
    |
    | Minimum duration requirements for sections to be considered valid.
    | This prevents micro-segments from being processed.
    |
    */
    'min_section_duration' => env('LIVESTREAM_MIN_SECTION_DURATION', 60.0), // seconds
    'min_sermon_duration' => env('LIVESTREAM_MIN_SERMON_DURATION', 300.0), // 5 minutes minimum

    /*
    |--------------------------------------------------------------------------
    | FFmpeg Configuration
    |--------------------------------------------------------------------------
    |
    | Paths to FFmpeg and FFprobe binaries for video processing.
    | These should be absolute paths to the installed binaries.
    |
    */
    'ffmpeg_path' => env('FFMPEG_PATH', '/usr/bin/ffmpeg'),
    'ffprobe_path' => env('FFPROBE_PATH', '/usr/bin/ffprobe'),

    /*
    |--------------------------------------------------------------------------
    | File Size and Format Limits
    |--------------------------------------------------------------------------
    |
    | Maximum file size for uploaded videos and supported formats.
    |
    */
    'max_file_size' => env('LIVESTREAM_MAX_FILE_SIZE', 2147483648), // 2GB in bytes
    'supported_formats' => ['mp4', 'mov', 'avi', 'mkv'],

    /*
    |--------------------------------------------------------------------------
    | Format Aliases
    |--------------------------------------------------------------------------
    |
    | Maps file extensions to FFmpeg/FFprobe format names for validation.
    | This handles cases where FFmpeg reports different internal format names
    | than the file extension (e.g., MKV files are reported as "matroska").
    |
    */
    'format_aliases' => [
        'mkv' => ['matroska'],
        'webm' => ['matroska', 'webm'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage Configuration
    |--------------------------------------------------------------------------
    |
    | Storage disks for different types of files during processing.
    | - sermon_disk: Where final audio/video files are permanently stored
    | - temp_disk: Should always be 'local' for FFmpeg processing
    | - storage_disk: General storage for livestream files
    |
    | S3 Processing:
    | When sermon_disk is an S3-compatible disk (like do_spaces), the system
    | automatically uses hybrid processing: extract to local temp, then upload.
    |
    */
    'storage_disk' => env('LIVESTREAM_STORAGE_DISK', 'local'),
    'sermon_disk' => env('LIVESTREAM_SERMON_DISK', 'public'),
    'temp_disk' => env('LIVESTREAM_TEMP_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Processing Configuration
    |--------------------------------------------------------------------------
    |
    | Various processing parameters and timeouts.
    |
    */
    'processing_timeout' => env('LIVESTREAM_PROCESSING_TIMEOUT', 7200), // 2 hours in seconds
    'max_concurrent_jobs' => env('LIVESTREAM_MAX_CONCURRENT_JOBS', 2),
    'retry_attempts' => env('LIVESTREAM_RETRY_ATTEMPTS', 3),
    'retry_delay' => env('LIVESTREAM_RETRY_DELAY', 60), // seconds

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    |
    | Queue settings for processing jobs.
    |
    */
    'queue' => [
        'name' => env('LIVESTREAM_QUEUE_NAME', 'livestream-processing'),
        'connection' => env('LIVESTREAM_QUEUE_CONNECTION', 'database'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Notification Configuration
    |--------------------------------------------------------------------------
    |
    | Email addresses and notification settings for processing events.
    |
    */
    'admin_email' => env('LIVESTREAM_ADMIN_EMAIL', 'admin@crockenhill.org'),
    'notify_on_success' => env('LIVESTREAM_NOTIFY_SUCCESS', false),
    'notify_on_failure' => env('LIVESTREAM_NOTIFY_FAILURE', true),

    /*
    |--------------------------------------------------------------------------
    | Cleanup and Retention
    |--------------------------------------------------------------------------
    |
    | File retention policies and cleanup settings.
    |
    */
    'temp_file_retention_hours' => env('LIVESTREAM_TEMP_RETENTION_HOURS', 24),
    'failed_processing_retention_days' => env('LIVESTREAM_FAILED_RETENTION_DAYS', 7),
    'auto_cleanup_enabled' => env('LIVESTREAM_AUTO_CLEANUP', true),

    /*
    |--------------------------------------------------------------------------
    | Quality and Performance Settings
    |--------------------------------------------------------------------------
    |
    | Settings that affect processing quality and performance.
    |
    */
    'audio_sample_rate' => env('LIVESTREAM_AUDIO_SAMPLE_RATE', 44100),
    'video_quality_preset' => env('LIVESTREAM_VIDEO_PRESET', 'medium'), // ultrafast, superfast, veryfast, faster, fast, medium, slow, slower, veryslow
    'preserve_original_quality' => env('LIVESTREAM_PRESERVE_QUALITY', true),

    /*
    |--------------------------------------------------------------------------
    | Storage Paths
    |--------------------------------------------------------------------------
    |
    | Directory paths for organizing stored files.
    | audio_path should match sermon-processing config for consistency.
    |
    */
    'storage' => [
        'video_path' => env('LIVESTREAM_VIDEO_PATH', 'sermons/videos'),
        'audio_path' => env('LIVESTREAM_AUDIO_PATH', 'sermons/audio'),
        'temp_path' => env('LIVESTREAM_TEMP_PATH', 'temp/livestreams'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting Configuration
    |--------------------------------------------------------------------------
    |
    | API rate limiting settings. Can be disabled in development environments
    | by setting LIVESTREAM_RATE_LIMITING_ENABLED=false.
    |
    */
    'rate_limiting' => [
        'enabled' => env('LIVESTREAM_RATE_LIMITING_ENABLED', true),
        'upload' => [
            'per_minute' => env('LIVESTREAM_UPLOAD_RATE_PER_MINUTE', 1),
            'per_hour' => env('LIVESTREAM_UPLOAD_RATE_PER_HOUR', 5),
        ],
        'retry' => [
            'per_minute' => env('LIVESTREAM_RETRY_RATE_PER_MINUTE', 1),
            'per_hour' => env('LIVESTREAM_RETRY_RATE_PER_HOUR', 3),
        ],
        'status' => [
            'per_minute' => env('LIVESTREAM_STATUS_RATE_PER_MINUTE', 60),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Audio Extraction for Transcription
    |--------------------------------------------------------------------------
    |
    | Optimized audio extraction settings for transcription services.
    | These settings balance file size with transcription accuracy.
    |
    */
    'audio_extraction' => [
        'transcription_optimized' => [
            'bitrate' => 48, // kbps - balance of quality vs size
            'sample_rate' => 16000, // Hz - adequate for speech recognition
            'channels' => 1, // mono
            'max_file_size' => 25 * 1024 * 1024, // 25MB OpenAI Whisper limit
        ],
        'fallback_compression' => [
            'bitrate' => 32, // kbps - more aggressive compression
            'sample_rate' => 16000, // Hz
            'channels' => 1, // mono
        ],
        'validation' => [
            'max_duration_minutes' => 150, // ~3.6MB at 32kbps
            'size_check_enabled' => true,
            'quality_check_enabled' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | S3/Spaces Processing Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for S3-compatible storage processing.
    |
    */
    's3_processing' => [
        'upload_timeout' => env('LIVESTREAM_S3_UPLOAD_TIMEOUT', 300), // 5 minutes
        'retry_attempts' => env('LIVESTREAM_S3_RETRY_ATTEMPTS', 3),
        'retry_delay' => env('LIVESTREAM_S3_RETRY_DELAY', 5), // seconds
        'cleanup_temp_files' => env('LIVESTREAM_S3_CLEANUP_TEMP', true),
        'multipart_threshold' => env('LIVESTREAM_S3_MULTIPART_THRESHOLD', 100 * 1024 * 1024), // 100MB
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging Configuration
    |--------------------------------------------------------------------------
    |
    | Detailed logging settings for processing steps.
    |
    */
    'detailed_logging' => env('LIVESTREAM_DETAILED_LOGGING', true),
    'log_ffmpeg_output' => env('LIVESTREAM_LOG_FFMPEG', false),
    'log_s3_operations' => env('LIVESTREAM_LOG_S3_OPS', true),
    'performance_monitoring' => env('LIVESTREAM_PERFORMANCE_MONITORING', true),
];
