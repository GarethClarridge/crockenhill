<?php

return [
    'types' => [
        'audio' => [
            'max_file_size' => 100 * 1024 * 1024, // 100MB
            'allowed_extensions' => ['mp3', 'wav', 'm4a', 'mp4'],
            'allowed_mimes' => ['audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/x-wav', 'audio/mp4', 'audio/m4a'],
            'queue' => 'audio-processing',
            'description' => 'Audio sermon files',
        ],
        'video' => [
            'max_file_size' => 1024 * 1024 * 1024, // 1GB (reasonable middle ground)
            'allowed_extensions' => ['mp4', 'mov', 'avi', 'mkv'],
            'allowed_mimes' => ['video/mp4', 'video/quicktime', 'video/x-msvideo', 'video/x-matroska'],
            'queue' => 'video-processing',
            'description' => 'Direct sermon video files',
        ],
        'livestream' => [
            'max_file_size' => 2 * 1024 * 1024 * 1024, // 2GB
            'allowed_extensions' => ['mp4', 'mov', 'avi', 'mkv', 'webm'],
            'allowed_mimes' => ['video/mp4', 'video/quicktime', 'video/x-msvideo', 'video/x-matroska', 'video/webm'],
            'queue' => 'livestream-processing',
            'description' => 'Full livestream recordings requiring segmentation',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | S3/DigitalOcean Spaces Storage Configuration
    |--------------------------------------------------------------------------
    |
    | CRITICAL: This system uses sophisticated hybrid processing for S3-compatible
    | storage (DigitalOcean Spaces). The system automatically detects S3-compatible
    | disks and uses hybrid processing: local temp processing → cloud upload.
    |
    | - sermon_disk: Final storage (can be do_spaces, s3, or local)
    | - temp_disk: MUST be 'local' for FFmpeg processing
    | - storage_disk: General file storage
    |
    */
    'storage' => [
        // Main storage disk - can be S3-compatible (do_spaces) or local
        'disk' => env('MEDIA_STORAGE_DISK', 'do_spaces'),
        'sermon_disk' => env('SERMON_STORAGE_DISK', 'do_spaces'),

        // Temporary processing - MUST be local for FFmpeg
        'temp_disk' => 'local',

        // Storage paths
        'paths' => [
            'audio' => env('MEDIA_AUDIO_PATH', 'sermons/audio'),
            'video' => env('MEDIA_VIDEO_PATH', 'sermons/video'),
            'temp' => env('MEDIA_TEMP_PATH', 'temp/media-processing'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | S3 Hybrid Processing Configuration
    |--------------------------------------------------------------------------
    |
    | The system auto-detects S3-compatible disks and uses hybrid processing:
    | 1. Process files locally in temp directory
    | 2. Upload final results to S3-compatible storage
    | 3. Clean up local temp files
    |
    */
    's3_processing' => [
        'upload_timeout' => env('S3_UPLOAD_TIMEOUT', 300), // 5 minutes
        'retry_attempts' => env('S3_RETRY_ATTEMPTS', 3),
        'retry_delay' => env('S3_RETRY_DELAY', 5), // seconds
        'cleanup_temp_files' => env('S3_CLEANUP_TEMP', true),
        'multipart_threshold' => env('S3_MULTIPART_THRESHOLD', 100 * 1024 * 1024), // 100MB
    ],

    'processing' => [
        'timeout' => 7200, // 2 hours
        'retry_attempts' => 3,
        'retry_delay' => 60,
        'max_concurrent_jobs' => 2,
    ],

    'transcription' => [
        'service' => env('TRANSCRIPTION_SERVICE_TYPE', 'openai'),
        'openai_api_key' => env('OPENAI_API_KEY'),
        'max_file_size' => 25 * 1024 * 1024, // 25MB
        'timeout' => 300,
    ],

    'ffmpeg' => [
        'ffmpeg_path' => env('FFMPEG_PATH', '/usr/local/bin/ffmpeg'),
        'ffprobe_path' => env('FFPROBE_PATH', '/usr/local/bin/ffprobe'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Livestream Segmentation Configuration
    |--------------------------------------------------------------------------
    |
    | RMS analysis and segmentation settings for livestream processing
    |
    */
    'segmentation' => [
        'rms_threshold' => (float) env('RMS_THRESHOLD', -45.0),
        'min_section_duration' => (float) env('MIN_SECTION_DURATION', 60.0),
        'min_sermon_duration' => (float) env('MIN_SERMON_DURATION', 300.0),
        'adaptive_thresholds' => [
            'enabled' => env('ADAPTIVE_THRESHOLDS_ENABLED', true),
            'speech_percentile' => env('SPEECH_PERCENTILE', 30),
            'fallback_enabled' => env('ADAPTIVE_FALLBACK_ENABLED', true),
            'min_threshold' => (float) env('MIN_THRESHOLD', -80.0),
            'max_threshold' => (float) env('MAX_THRESHOLD', -20.0),
            'min_sample_count' => env('MIN_SAMPLE_COUNT', 1000),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Audio Extraction for Transcription
    |--------------------------------------------------------------------------
    |
    | Optimized audio extraction settings for transcription services
    |
    */
    'audio_extraction' => [
        'transcription_optimized' => [
            'bitrate' => 48, // kbps
            'sample_rate' => 16000, // Hz
            'channels' => 1, // mono
            'max_file_size' => 25 * 1024 * 1024, // 25MB OpenAI Whisper limit
        ],
        'fallback_compression' => [
            'bitrate' => 32, // kbps
            'sample_rate' => 16000, // Hz
            'channels' => 1, // mono
        ],
        'validation' => [
            'max_duration_minutes' => 150,
            'size_check_enabled' => true,
            'quality_check_enabled' => true,
        ],
    ],
];
