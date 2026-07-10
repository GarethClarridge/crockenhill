<?php

use App\Services\ChurchService\SectionPublication\SermonPublicationHandler;
use App\Services\ChurchService\SectionPublication\SongPublicationHandler;

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
            'max_file_size' => (int) env('MEDIA_PROCESSING_LIVESTREAM_MAX_FILE_SIZE', 8 * 1024 * 1024 * 1024), // 8GB
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
        // Shared minimum free-space floor (GB) for the local temp disk — the genuine
        // pipeline bottleneck. The upload validator and the historic importer guard read
        // this single value via TempDiskSpace so they never disagree about how much
        // headroom a dispatch needs.
        'temp_disk_min_free_gb' => (int) env('MEDIA_PROCESSING_TEMP_DISK_MIN_FREE_GB', 20),
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

    /*
    |--------------------------------------------------------------------------
    | Server-Sent Events
    |--------------------------------------------------------------------------
    | Tuning knobs for the /api/media/processing/{id}/stream SSE endpoint.
    | Tests override poll_seconds to 0 to avoid stalling on sleep().
    */
    'sse' => [
        'poll_seconds' => (int) env('MEDIA_SSE_POLL_SECONDS', 2),
        'max_duration_seconds' => (int) env('MEDIA_SSE_MAX_DURATION_SECONDS', 3600),
    ],

    'transcription' => [
        'service' => env('TRANSCRIPTION_SERVICE_TYPE', 'mock'),
        'openai_api_key' => env('OPENAI_API_KEY'),
        'max_file_size' => 25 * 1024 * 1024,
        'timeout' => 300,
        'job_timeout' => (int) env('TRANSCRIPTION_JOB_TIMEOUT', 1800),
        'max_retries' => env('TRANSCRIPTION_MAX_RETRIES', 3),
        'retry_delay_base' => env('TRANSCRIPTION_RETRY_DELAY_BASE', 2),
        'local_whisper_url' => env('LOCAL_WHISPER_URL', 'http://whisper:8000'),
        'local_whisper_transcription_path' => env('LOCAL_WHISPER_TRANSCRIPTION_PATH', '/v1/audio/transcriptions'),
        'local_whisper_model' => env('LOCAL_WHISPER_MODEL', 'small'),
        'local_whisper_timeout' => (int) env('LOCAL_WHISPER_TIMEOUT', 1800),
        'local_whisper_serialize' => (bool) env('LOCAL_WHISPER_SERIALIZE', true),
        'local_whisper_lock_release_after' => (int) env('LOCAL_WHISPER_LOCK_RELEASE_AFTER', 60),
    ],

    'analysis' => [
        'service' => env('ANALYSIS_SERVICE', 'mock'),
        'openai_api_key' => env('OPENAI_API_KEY'),
        // Dedicated knob (was the shared OPENAI_MODEL) so sermon analysis can diverge from the
        // lower-stakes email parser; defaults to a reasoning model for better public summaries.
        'model' => env('ANALYSIS_MODEL', 'gpt-5-mini'),
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
        // Skip the encode pass when measured loudness is already within this many LUFS of the target.
        'skip_if_within_tolerance' => env('AUDIO_ENHANCEMENT_SKIP_IF_WITHIN_TOLERANCE', true),
        'skip_tolerance_lufs' => (float) env('AUDIO_ENHANCEMENT_SKIP_TOLERANCE_LUFS', 2.0),
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
        // Speech-section classification drives the high-confidence auto-extraction decision and
        // the sermon-vs-children's-talk judgement, so it gets its own explicit model knob
        // (defaulting to the flagship reasoning model) rather than the cheaper analysis default.
        'model' => env('SERVICE_SECTION_CLASSIFICATION_MODEL', 'gpt-5'),
        'childrens_talk_min_preceding_duration_seconds' => (int) env('SERVICE_SECTION_CHILDRENS_TALK_MIN_PRECEDING_DURATION_SECONDS', 300),
        'intro_max_start_seconds' => (int) env('SERVICE_SECTION_INTRO_MAX_START_SECONDS', 120),
        'outro_min_remaining_seconds' => (int) env('SERVICE_SECTION_OUTRO_MIN_REMAINING_SECONDS', 30),
        'outro_max_duration_seconds' => (int) env('SERVICE_SECTION_OUTRO_MAX_DURATION_SECONDS', 60),
        'adjacent_merge_min_duration_seconds' => (int) env('SERVICE_SECTION_ADJACENT_MERGE_MIN_DURATION_SECONDS', 30),
        'adjacent_merge_max_gap_seconds' => (int) env('SERVICE_SECTION_ADJACENT_MERGE_MAX_GAP_SECONDS', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reading Reference Resolution (Improvement #1)
    |--------------------------------------------------------------------------
    | Derives the actual scripture reference for a bible_reading section from the
    | recited transcript when the OoS only carries a generic "Bible Reading" title.
    | Gated by the analysis-service switch as well, so the `mock` service never
    | makes a live call in tests.
    */
    'reading_references' => [
        'enabled' => env('READING_REFERENCES_ENABLED', true),
        'model' => env('READING_REFERENCES_MODEL', 'gpt-5-mini'),
        'min_confidence' => (float) env('READING_REFERENCES_MIN_CONFIDENCE', 0.6),
        // A short closing section whose transcript is a benediction formula is not a reading,
        // even if the model names a passage (F12). The duration + end-of-service position guard
        // means a benediction genuinely read mid-service is still resolved.
        'benediction_max_duration_seconds' => (float) env('READING_REFERENCES_BENEDICTION_MAX_DURATION', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | LLM-First Service Structure Pipeline
    |--------------------------------------------------------------------------
    | Full-service transcription + one-call LLM structure detection, replacing
    | the heuristic classification cluster once promoted. Rolled out via `mode`
    | (off → shadow → primary); everything defaults dark. Unknown values throw
    | at resolution time — there is deliberately no silent fallback.
    */
    'service_structure' => [
        // off|shadow|primary — the rollout switch (ServiceStructureMode).
        'mode' => env('SERVICE_STRUCTURE_MODE', 'off'),
        // mock|openai — the ServiceStructureInterface binding.
        'detector' => env('SERVICE_STRUCTURE_DETECTOR', 'mock'),
        // Owns the sermon-vs-children's-talk judgement, so it defaults to the
        // flagship reasoning model (same reasoning as section_classification.model).
        'model' => env('SERVICE_STRUCTURE_MODEL', 'gpt-5'),
        // Candidate model for shadow runs. When set, shadow detection uses
        // this model while `model` stays authoritative — the permanent
        // model-upgrade mechanism once the heuristic baseline is retired.
        // Null means shadow runs the bound model.
        'shadow_model' => env('SERVICE_STRUCTURE_SHADOW_MODEL'),
        // When a validated structure has a sermon but no bible_reading section
        // within the extraction pairing window before it, retry detection once
        // with feedback naming the anomaly (the reading is usually embedded in
        // another section). The retry is adopted only if it validates and
        // recovers a reading.
        'reading_recheck' => env('SERVICE_STRUCTURE_READING_RECHECK', true),
        // mock|openai|local — the ServiceTranscriptionInterface binding.
        'transcription_service' => env('SERVICE_TRANSCRIPTION_SERVICE', 'mock'),
        // Whisper model for the whole-recording pass. Must support verbose_json
        // segment timestamps (whisper-1); gpt-4o-transcribe does not.
        'transcription_model' => env('SERVICE_TRANSCRIPTION_MODEL', 'whisper-1'),
        // Deterministic gate knobs: how far a boundary may snap to silence,
        // the micro-section review threshold, and the hard floor on how much
        // of the recording's speech the detected sections must cover.
        'snap_window_seconds' => (int) env('SERVICE_STRUCTURE_SNAP_WINDOW', 30),
        'min_section_seconds' => (int) env('SERVICE_STRUCTURE_MIN_SECTION', 15),
        'coverage_floor' => (float) env('SERVICE_STRUCTURE_COVERAGE_FLOOR', 0.7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Media / Video Interludes (Improvement #5)
    |--------------------------------------------------------------------------
    | Tags detected blocks that align to an OoS `media` item (e.g. "Bibles.mp4")
    | as structural interludes rather than mis-classifying their speech-over-video
    | as prayer/notices. Audio + transcript + OoS only — never relies on the
    | projector video being present in the livestream feed.
    */
    'media_interludes' => [
        'enabled' => env('MEDIA_INTERLUDES_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Transition Microsections
    |--------------------------------------------------------------------------
    | Short "other" blips between non-speech sections (e.g. a 10s inter-song
    | image) are tagged as transitions so they stop generating review noise and
    | are excluded from structural alignment counting.
    */
    'transitions' => [
        'max_duration_seconds' => (int) env('SERVICE_SECTION_TRANSITION_MAX_DURATION_SECONDS', 15),
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
        'use_local_whisper_for_song_openings' => (bool) env('SONG_MATCHING_USE_LOCAL_WHISPER_FOR_OPENINGS', env('APP_ENV') === 'local'),
        'song_opening_local_whisper_url' => env('SONG_MATCHING_LOCAL_WHISPER_URL', 'http://whisper:8000'),
        'song_opening_local_whisper_transcription_path' => env('SONG_MATCHING_LOCAL_WHISPER_TRANSCRIPTION_PATH', '/v1/audio/transcriptions'),
        'song_opening_local_whisper_model' => env('SONG_MATCHING_LOCAL_WHISPER_MODEL', env('LOCAL_WHISPER_MODEL', 'small')),
        'song_opening_local_whisper_timeout' => (int) env('SONG_MATCHING_LOCAL_WHISPER_TIMEOUT', env('LOCAL_WHISPER_TIMEOUT', 1800)),
        'lyrics_threshold' => (float) env('SONG_MATCHING_LYRICS_THRESHOLD', 0.6),
        // Matches at or above this confidence rewrite the section's display
        // title to the catalogued song title; below it only the match record
        // is stored and the heard text stays on display.
        'title_writeback_min_confidence' => (float) env('SONG_MATCHING_TITLE_WRITEBACK_MIN_CONFIDENCE', 0.75),
        'ocr_enabled' => env('SONG_MATCHING_OCR_ENABLED', true),
        'ocr_model' => env('SONG_MATCHING_OCR_MODEL', 'gpt-5-mini'),
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
            // Beyond this gap a bible reading is too far from the sermon to be the preached
            // text, so it is not paired (F3). 15 minutes is deliberately generous.
            'max_pairing_gap_seconds' => 900,
            // Readings shorter than this are demoted (not excluded) when ranking the preached
            // text, so a short "let us turn to..." preamble loses to the substantive reading (F17).
            'min_reading_duration_seconds' => 90,
            // A sermon span longer than this is implausible and indicates under-segmentation
            // (e.g. RMS collapsing a whole service into one block). The run is routed to manual
            // review rather than silently extracting the wrong content (F10).
            'max_sermon_duration_seconds' => 2700,
        ],
    ],

    'video_auto_trim' => [
        'enabled' => env('VIDEO_AUTO_TRIM_ENABLED', true),
        'max_file_size' => 1024 * 1024 * 1024, // 1GB - matches sermon video uploads
        'manual_review' => [
            'enabled' => env('VIDEO_AUTO_TRIM_MANUAL_REVIEW_ENABLED', true),
        ],
        // Auto-trim intentionally skips song-catalog matching and instead treats
        // unmatched leading/trailing song-like sections as trim candidates.
    ],

    'video_quality' => [
        'enabled' => env('SERMON_VIDEO_QUALITY_ENABLED', true),
        'enforce_public_visibility' => env('SERMON_VIDEO_QUALITY_ENFORCE_VISIBILITY', true),
        'hide_needs_review' => env('SERMON_VIDEO_QUALITY_HIDE_NEEDS_REVIEW', false),
        'auto_reject_frozen_frames' => env('SERMON_VIDEO_QUALITY_AUTO_REJECT_FROZEN', true),
        'sampling' => [
            'coarse_sample_count' => (int) env('SERMON_VIDEO_QUALITY_COARSE_SAMPLES', 8),
            'middle_start_ratio' => (float) env('SERMON_VIDEO_QUALITY_MIDDLE_START_RATIO', 0.2),
            'middle_end_ratio' => (float) env('SERMON_VIDEO_QUALITY_MIDDLE_END_RATIO', 0.8),
            'burst_window_count' => (int) env('SERMON_VIDEO_QUALITY_BURST_WINDOWS', 2),
            'burst_frames_per_window' => (int) env('SERMON_VIDEO_QUALITY_BURST_FRAMES', 5),
            'burst_frame_gap_seconds' => (float) env('SERMON_VIDEO_QUALITY_BURST_GAP_SECONDS', 1.5),
        ],
        'thresholds' => [
            'blank_dark_brightness' => (float) env('SERMON_VIDEO_QUALITY_BLANK_DARK_BRIGHTNESS', 0.08),
            'blank_light_brightness' => (float) env('SERMON_VIDEO_QUALITY_BLANK_LIGHT_BRIGHTNESS', 0.97),
            'blank_variance' => (float) env('SERMON_VIDEO_QUALITY_BLANK_VARIANCE', 0.0005),
            'blank_frame_ratio_reject' => (float) env('SERMON_VIDEO_QUALITY_BLANK_FRAME_RATIO_REJECT', 0.75),
            'low_detail_score' => (float) env('SERMON_VIDEO_QUALITY_LOW_DETAIL_SCORE', 0.04),
            'low_detail_ratio_review' => (float) env('SERMON_VIDEO_QUALITY_LOW_DETAIL_RATIO_REVIEW', 0.75),
            'low_detail_ratio_reject' => (float) env('SERMON_VIDEO_QUALITY_LOW_DETAIL_RATIO_REJECT', 0.95),
            'frozen_frame_diff' => (float) env('SERMON_VIDEO_QUALITY_FROZEN_FRAME_DIFF', 0.01),
            'frozen_pair_ratio_reject' => (float) env('SERMON_VIDEO_QUALITY_FROZEN_PAIR_RATIO_REJECT', 0.95),
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
            'childrens_talk' => SermonPublicationHandler::class,
            'song' => SongPublicationHandler::class,
            // 'sermon' => \App\Services\ChurchService\SectionPublication\SermonPublicationHandler::class, // future
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
