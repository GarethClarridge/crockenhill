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
    */
  'rms_threshold' => env('LIVESTREAM_RMS_THRESHOLD', -30.0),

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
    | Storage Configuration
    |--------------------------------------------------------------------------
    |
    | Storage disks for different types of files during processing.
    |
    */
  'storage_disk' => env('LIVESTREAM_STORAGE_DISK', 'local'),
  'sermon_disk' => env('LIVESTREAM_SERMON_DISK', 'local'),
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
    | Notification Configuration
    |--------------------------------------------------------------------------
    |
    | Email addresses and notification settings for processing events.
    |
    */
  'admin_email' => env('LIVESTREAM_ADMIN_EMAIL', config('mail.from.address')),
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
    | Logging Configuration
    |--------------------------------------------------------------------------
    |
    | Detailed logging settings for processing steps.
    |
    */
  'detailed_logging' => env('LIVESTREAM_DETAILED_LOGGING', true),
  'log_ffmpeg_output' => env('LIVESTREAM_LOG_FFMPEG', false),
  'performance_monitoring' => env('LIVESTREAM_PERFORMANCE_MONITORING', true),
];
