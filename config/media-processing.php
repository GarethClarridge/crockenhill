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
            'max_file_size' => 1024 * 1024 * 1024, // 1GB
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
    | Storage Configuration
    |--------------------------------------------------------------------------
    */
    'storage' => [
        'disk' => env('SERMON_STORAGE_DISK', env('FILESYSTEM_DISK', 'public')),
        'sermon_disk' => env('SERMON_STORAGE_DISK', env('FILESYSTEM_DISK', 'public')),
        'temp_disk' => 'local',
        'paths' => [
            'audio' => 'sermons/audio',
            'video' => 'sermons/video',
            'temp' => 'temp/media-processing',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | S3 Hybrid Processing
    |--------------------------------------------------------------------------
    */
    's3_processing' => [
        'upload_timeout' => 300,
        'retry_attempts' => 3,
        'retry_delay' => 5,
        'cleanup_temp_files' => true,
        'multipart_threshold' => 100 * 1024 * 1024,
    ],

    'processing' => [
        'timeout' => 7200,
        'retry_attempts' => 3,
        'retry_delay' => 60,
        'max_concurrent_jobs' => 2,
    ],

    /*
    |--------------------------------------------------------------------------
    | Email Notifications
    |--------------------------------------------------------------------------
    */
    'email' => [
        'admin_email' => env('LIVESTREAM_ADMIN_EMAIL', env('MAIL_FROM_ADDRESS')),
        'send_success_notifications' => env('LIVESTREAM_NOTIFY_SUCCESS', false),
        'send_failure_notifications' => env('LIVESTREAM_NOTIFY_FAILURE', true),
    ],

    'transcription' => [
        'service' => env('TRANSCRIPTION_SERVICE_TYPE', 'mock'),
        'openai_api_key' => env('OPENAI_API_KEY'),
        'max_file_size' => 25 * 1024 * 1024,
        'timeout' => 300,
    ],

    'ffmpeg' => [
        'ffmpeg_path' => env('FFMPEG_PATH', '/usr/bin/ffmpeg'),
        'ffprobe_path' => env('FFPROBE_PATH', '/usr/bin/ffprobe'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Livestream Segmentation
    |--------------------------------------------------------------------------
    */
    'segmentation' => [
        'rms_threshold' => -45.0,
        'min_section_duration' => 60.0,
        'min_sermon_duration' => 300.0,
        'adaptive_thresholds' => [
            'enabled' => true,
            'speech_percentile' => 30,
            'fallback_enabled' => true,
            'min_threshold' => -80.0,
            'max_threshold' => -20.0,
            'min_sample_count' => 1000,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Audio Extraction for Transcription
    |--------------------------------------------------------------------------
    */
    'audio_extraction' => [
        'transcription_optimized' => [
            'bitrate' => 48,
            'sample_rate' => 16000,
            'channels' => 1,
            'max_file_size' => 25 * 1024 * 1024,
        ],
        'fallback_compression' => [
            'bitrate' => 32,
            'sample_rate' => 16000,
            'channels' => 1,
        ],
        'validation' => [
            'max_duration_minutes' => 150,
            'size_check_enabled' => true,
            'quality_check_enabled' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Visual Song Detection
    |--------------------------------------------------------------------------
    */
    'visual_analysis' => [
        'enabled' => true,
        'sample_interval_seconds' => 10,
        'brightness_threshold' => 0.48,
        'contrast_threshold' => 0.0,
        'edge_density_threshold' => 0.75,
        'min_confidence' => 0.35,
        'min_song_duration' => 60,
        'max_gap_seconds' => 30,
        'smoothing_window' => 3,
        'dense_sample_interval' => 1,
        'refinement_intro_buffer' => 120,
        'refinement_outro_buffer' => 60,
        'intro_search_buffer' => 120,
        'outro_search_buffer' => 60,
        'quiet_section_tolerance' => 10,
        'calibration_speech_buffer' => 60,
        'threshold_safety_floor' => -80.0,
        'threshold_safety_ceiling' => -20.0,
        'fallback_to_rms_on_failure' => true,
        'require_min_clusters' => 1,
    ],
];
