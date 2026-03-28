<?php

$defaultQueue = env('MEDIA_PROCESSING_QUEUE_DEFAULT', 'default');
$audioQueue = env('MEDIA_PROCESSING_QUEUE_AUDIO', 'audio-processing');
$videoQueue = env('MEDIA_PROCESSING_QUEUE_VIDEO', 'video-processing');
$livestreamQueue = env('MEDIA_PROCESSING_QUEUE_LIVESTREAM', 'livestream-processing');
$livestreamAudioQueue = env('MEDIA_PROCESSING_QUEUE_LIVESTREAM_AUDIO', $audioQueue);
$speakerIdentificationQueue = env('MEDIA_PROCESSING_QUEUE_SPEAKER_IDENTIFICATION', 'speaker-identification');

return [
    'queues' => [
        'default' => $defaultQueue,
        'audio' => $audioQueue,
        'video' => $videoQueue,
        'livestream' => $livestreamQueue,
        'livestream_audio' => $livestreamAudioQueue,
        'speaker_identification' => $speakerIdentificationQueue,
    ],

    'types' => [
        'audio' => [
            'max_file_size' => 100 * 1024 * 1024, // 100MB
            'allowed_extensions' => ['mp3', 'wav', 'm4a', 'mp4'],
            'allowed_mimes' => ['audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/x-wav', 'audio/mp4', 'audio/m4a'],
            'queue' => $audioQueue,
            'description' => 'Audio sermon files',
        ],
        'video' => [
            'max_file_size' => 1024 * 1024 * 1024, // 1GB
            'allowed_extensions' => ['mp4', 'mov', 'avi', 'mkv'],
            'allowed_mimes' => ['video/mp4', 'video/quicktime', 'video/x-msvideo', 'video/x-matroska'],
            'queue' => $videoQueue,
            'description' => 'Direct sermon video files',
        ],
        'livestream' => [
            'max_file_size' => 2 * 1024 * 1024 * 1024, // 2GB
            'allowed_extensions' => ['mp4', 'mov', 'avi', 'mkv', 'webm'],
            'allowed_mimes' => ['video/mp4', 'video/quicktime', 'video/x-msvideo', 'video/x-matroska', 'video/webm'],
            'queue' => $livestreamQueue,
            'description' => 'Full livestream recordings requiring segmentation',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage Configuration
    |--------------------------------------------------------------------------
    */
    'storage' => [
        // SERMON_STORAGE_DISK is the canonical key; falls back to the default filesystem disk.
        'sermon_disk' => env('SERMON_STORAGE_DISK', env('FILESYSTEM_DISK', 'local')),
        // TRANSCRIPT_STORAGE_DISK is the canonical key; falls back to sermon disk, then filesystem disk.
        'transcript_disk' => env('TRANSCRIPT_STORAGE_DISK', env('SERMON_STORAGE_DISK', env('FILESYSTEM_DISK', 'local'))),
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
        'retry_attempts' => 3,
        'retry_delay' => 60,
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
        'max_retries' => env('TRANSCRIPTION_MAX_RETRIES', 3),
        'retry_delay_base' => env('TRANSCRIPTION_RETRY_DELAY_BASE', 2),
        'local_whisper_url' => env('LOCAL_WHISPER_URL', 'http://whisper:8000'),
        'local_whisper_model' => env('LOCAL_WHISPER_MODEL', 'small'),
        'local_whisper_timeout' => (int) env('LOCAL_WHISPER_TIMEOUT', 600),
    ],

    'analysis' => [
        'service' => env('ANALYSIS_SERVICE', 'mock'),
        'openai_api_key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'max_retries' => env('ANALYSIS_MAX_RETRIES', 3),
        'retry_delay_base' => env('ANALYSIS_RETRY_DELAY_BASE', 2),
        'debug_http_responses' => env('OPENAI_DEBUG_HTTP_RESPONSES', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Speaker Identification
    |--------------------------------------------------------------------------
    */
    'speaker_identification' => [
        'enabled' => env('SPEAKER_IDENTIFICATION_ENABLED', false),
        'mode' => env('SPEAKER_IDENTIFICATION_MODE', 'shadow'),
        'provider' => env('SPEAKER_IDENTIFICATION_PROVIDER', 'null'),
        'model_version' => env('SPEAKER_MODEL_VERSION', 'v1.0'),
        'queue' => $speakerIdentificationQueue,
        'accept_threshold' => (float) env('SPEAKER_ACCEPT_THRESHOLD', 0.75),
        'margin_threshold' => (float) env('SPEAKER_MARGIN_THRESHOLD', 0.10),
        'min_duration' => (int) env('SPEAKER_MIN_DURATION', 30),
        'extraction_duration' => (int) env('SPEAKER_EXTRACTION_DURATION', 60),
        'python_path' => env('SPEAKER_PYTHON_PATH', 'python3'),
        'script_path' => env('SPEAKER_SCRIPT_PATH', base_path('scripts/extract_embedding.py')),
    ],

    'ffmpeg' => [
        'ffmpeg_path' => env('FFMPEG_PATH', '/usr/bin/ffmpeg'),
        'ffprobe_path' => env('FFPROBE_PATH', '/usr/bin/ffprobe'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Audio Enhancement
    |--------------------------------------------------------------------------
    */
    'audio_enhancement' => [
        'enabled' => env('AUDIO_ENHANCEMENT_ENABLED', true),
        'noise_reduction' => env('AUDIO_ENHANCEMENT_NOISE_REDUCTION', true),
        'dynamic_norm' => env('AUDIO_ENHANCEMENT_DYNAMIC_NORM', true),
        'loudness_norm' => env('AUDIO_ENHANCEMENT_LOUDNESS_NORM', true),
        'target_lufs' => (float) env('AUDIO_ENHANCEMENT_TARGET_LUFS', -16.0),
        'true_peak' => (float) env('AUDIO_ENHANCEMENT_TRUE_PEAK', -1.5),
        'lra' => (float) env('AUDIO_ENHANCEMENT_LRA', 11.0),
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
    | Service Section Classification
    |--------------------------------------------------------------------------
    */
    'section_classification' => [
        'enabled' => env('SERVICE_SECTION_CLASSIFICATION_ENABLED', true),
        'prefer_high_confidence_sermon_section' => env('SERVICE_SECTION_PREFER_HIGH_CONFIDENCE_SERMON', true),
        'transcribe_speech_segments' => env('SERVICE_SECTION_TRANSCRIBE_SPEECH_SEGMENTS', true),
        'classify_speech_sections' => env('SERVICE_SECTION_CLASSIFY_SPEECH_SECTIONS', true),
        'speech_transcription_min_duration_seconds' => (int) env('SERVICE_SECTION_SPEECH_TRANSCRIPTION_MIN_DURATION_SECONDS', 10),
        'short_song_max_duration_seconds' => (int) env('SERVICE_SECTION_SHORT_SONG_MAX_DURATION_SECONDS', 90),
        'childrens_talk_max_duration_seconds' => (int) env('SERVICE_SECTION_CHILDRENS_TALK_MAX_DURATION_SECONDS', 900),
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'childrens_talk_min_preceding_duration_seconds' => (int) env('SERVICE_SECTION_CHILDRENS_TALK_MIN_PRECEDING_DURATION_SECONDS', 300),
        'intro_max_start_seconds' => (int) env('SERVICE_SECTION_INTRO_MAX_START_SECONDS', 120),
        'outro_min_remaining_seconds' => (int) env('SERVICE_SECTION_OUTRO_MIN_REMAINING_SECONDS', 30),
        'outro_max_duration_seconds' => (int) env('SERVICE_SECTION_OUTRO_MAX_DURATION_SECONDS', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Song Matching (Phase 4)
    |--------------------------------------------------------------------------
    */
    'song_matching' => [
        'enabled' => env('SONG_MATCHING_ENABLED', true),
        'transcribe_song_openings' => env('SONG_MATCHING_TRANSCRIBE_OPENINGS', true),
        'song_opening_transcription_seconds' => (int) env('SONG_MATCHING_OPENING_SECONDS', 30),
        'lyrics_threshold' => (float) env('SONG_MATCHING_LYRICS_THRESHOLD', 0.6),
    ],

    /*
    |--------------------------------------------------------------------------
    | Section Extraction (Phase 3)
    |--------------------------------------------------------------------------
    */
    'section_extraction' => [
        'enhanced_sermon' => [
            'enabled' => env('SERVICE_SECTION_ENHANCED_SERMON_ENABLED', true),
            'adjacent_gap_seconds' => 60,
            'allow_non_adjacent_concat' => env('SERVICE_SECTION_ALLOW_NON_ADJACENT_CONCAT', true),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Section Publishing (Phase 3)
    |--------------------------------------------------------------------------
    */
    'section_publishing' => [
        'enabled' => env('SERVICE_SECTION_PUBLISHING_ENABLED', true),
        'handlers' => [
            'childrens_talk' => \App\Services\SectionPublication\SermonPublicationHandler::class,
            'song' => \App\Services\SectionPublication\SongPublicationHandler::class,
            // 'sermon' => \App\Services\SectionPublication\SermonPublicationHandler::class, // future
        ],
        'require_high_confidence' => env('SERVICE_SECTION_PUBLISH_REQUIRE_HIGH_CONFIDENCE', true),
        'retain_unpublished_hours' => (int) env('SERVICE_SECTION_RETAIN_UNPUBLISHED_HOURS', 48),
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
        'fallback_to_rms_on_failure' => true,
        'require_min_clusters' => 1,
    ],
];
