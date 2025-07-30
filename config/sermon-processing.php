<?php

return [
  /*
    |--------------------------------------------------------------------------
    | Transcription Service Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for audio transcription services
    |
    */
  'transcription' => [
    'service' => env('TRANSCRIPTION_SERVICE', 'openai'),
    'openai_api_key' => env('OPENAI_API_KEY'),
    'max_file_size' => 25 * 1024 * 1024, // 25MB
    'timeout' => 300, // 5 minutes
  ],

  /*
    |--------------------------------------------------------------------------
    | AI Analysis Service Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for AI content analysis services
    |
    */
  'analysis' => [
    'service' => env('ANALYSIS_SERVICE', 'openai'),
    'model' => env('OPENAI_MODEL', 'gpt-3.5-turbo'),
    'max_title_words' => 12,
    'timeout' => 60,
  ],

  /*
    |--------------------------------------------------------------------------
    | Processing Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for sermon processing pipeline
    |
    */
  'processing' => [
    'queue' => env('SERMON_PROCESSING_QUEUE', 'default'),
    'retry_attempts' => 3,
    'retry_delay' => 60, // seconds
    'max_file_size' => 100 * 1024 * 1024, // 100MB
    'allowed_mime_types' => [
      'audio/mpeg',
      'audio/mp3',
      'audio/wav',
      'audio/x-wav',
      'audio/mp4',
      'audio/m4a',
    ],
    'allowed_extensions' => ['mp3', 'wav', 'm4a', 'mp4'],
  ],

  /*
    |--------------------------------------------------------------------------
    | Storage Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for file storage
    |
    */
  'storage' => [
    'disk' => env('SERMON_STORAGE_DISK', 'public'),
    'audio_path' => 'sermons',
    'transcript_path' => 'transcripts',
  ],

  /*
    |--------------------------------------------------------------------------
    | Notification Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for completion notifications
    |
    */
  'notifications' => [
    'enabled' => env('SERMON_NOTIFICATIONS_ENABLED', true),
    'admin_emails' => env('SERMON_ADMIN_EMAILS', ''),
  ],
];
