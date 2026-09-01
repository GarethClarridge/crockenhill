<?php

use App\Services\ChurchService\SectionPublication\SermonPublicationHandler;
use App\Services\ChurchService\SectionPublication\SongPublicationHandler;

$defaultQueue = env('MEDIA_PROCESSING_QUEUE_DEFAULT', 'default');
$audioQueue = env('MEDIA_PROCESSING_QUEUE_AUDIO', 'audio-processing');
$videoQueue = env('MEDIA_PROCESSING_QUEUE_VIDEO', 'video-processing');
$livestreamQueue = env('MEDIA_PROCESSING_QUEUE_LIVESTREAM', 'livestream-processing');
$livestreamAudioQueue = env('MEDIA_PROCESSING_QUEUE_LIVESTREAM_AUDIO', $audioQueue);
$speakerIdentificationQueue = env('MEDIA_PROCESSING_QUEUE_SPEAKER_IDENTIFICATION', 'speaker-identification');
$historicFfmpegQueue = env('HISTORIC_MEDIA_QUEUE_FFMPEG', 'historic-ffmpeg');
$historicWhisperQueue = env('HISTORIC_MEDIA_QUEUE_WHISPER', 'historic-whisper');
$historicLlmQueue = env('HISTORIC_MEDIA_QUEUE_LLM', 'historic-llm');
$historicOrchestrationQueue = env('HISTORIC_MEDIA_QUEUE_ORCHESTRATION', 'historic-orchestration');

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
        'historic_staging_disk' => env('HISTORIC_STAGING_DISK', 'historic_staging'),
        'historic_quarantine_disk' => env('HISTORIC_QUARANTINE_DISK', 'historic_quarantine'),
        // MEDIA_PROCESSING_TEMP_DISK moves the pipeline's working space off the project volume.
        // Historic passes need tens of GB of concat/transcode scratch that `local`
        // (storage_path('app')) cannot supply when the host volume is full.
        'temp_disk' => env('MEDIA_PROCESSING_TEMP_DISK', 'local'),
        'metadata_cache_ttl' => (int) env('SERMON_METADATA_CACHE_TTL', 3600),
        // Shared minimum free-space floor (GB) for the local temp disk — the genuine
        // pipeline bottleneck. The upload validator and the historic importer guard read
        // this single value via TempDiskSpace so they never disagree about how much
        // headroom a dispatch needs.
        'temp_disk_min_free_gb' => (int) env('MEDIA_PROCESSING_TEMP_DISK_MIN_FREE_GB', 20),
        // Declares the temp volume's free space unreadable from this process, so every gate that
        // sizes work from it stands down and the operator carries the headroom judgement instead.
        // Needed when the temp disk is a Docker bind mount onto a nested volume: disk_free_space()
        // then reports the *parent* filesystem, a confidently wrong number rather than a failure,
        // which no check can detect from the inside. Never set this on a volume that can be read.
        'temp_disk_unmeasurable' => (bool) env('MEDIA_PROCESSING_TEMP_DISK_UNMEASURABLE', false),
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
    | Historic archive throughput
    |--------------------------------------------------------------------------
    |
    | Historic imports use dedicated queues, so calibration can set the width
    | of the CPU-bound, single-GPU and remote-API stages independently without
    | changing the scheduling of the current weekly media pipeline. These values
    | are captured as historic execution evidence, separately from the durable
    | processing fingerprint.
    |
    */
    'historic_import' => [
        'evidence_signing_key' => env('HISTORIC_IMPORT_EVIDENCE_SIGNING_KEY'),
        // A processing row must have been inactive for this long before the
        // operator may recover its promotion/cleanup tail. This prevents a
        // recovery invocation from racing a live worker.
        'tail_recovery_stale_after_seconds' => (int) env('HISTORIC_IMPORT_TAIL_RECOVERY_STALE_AFTER_SECONDS', 3600),
        /*
         * HIR-D3 decided, against recommendation, that recovery evidence is
         * signed with the approval key above rather than a separate
         * recovery-only one. A distinct key *id* is still required, so the
         * decision can be revisited without a schema change — but while the
         * underlying secret is shared it attests integrity and approval-key
         * custody only, never verifier independence.
         */
        'recovery_evidence_key_id' => env('HISTORIC_IMPORT_RECOVERY_EVIDENCE_KEY_ID'),
        'transfer_retention_days' => (int) env('HISTORIC_TRANSFER_RETENTION_DAYS', 30),
        'convergence' => [
            // Bootstrap values used until the operation has observed its own
            // p95 apply and failed-apply cleanup durations.
            'admission_floor_seconds' => (float) env('HISTORIC_CONVERGENCE_ADMISSION_FLOOR_SECONDS', 5),
            'apply_p95_seconds' => env('HISTORIC_CONVERGENCE_APPLY_P95_SECONDS'),
            'rollback_p95_seconds' => env('HISTORIC_CONVERGENCE_ROLLBACK_P95_SECONDS'),
        ],
        'stages' => [
            'ffmpeg' => [
                'queue' => $historicFfmpegQueue,
                'workers' => (int) env('HISTORIC_MEDIA_WORKERS_FFMPEG', 1),
            ],
            'whisper' => [
                'queue' => $historicWhisperQueue,
                'workers' => (int) env('HISTORIC_MEDIA_WORKERS_WHISPER', 1),
            ],
            'llm' => [
                'queue' => $historicLlmQueue,
                'workers' => (int) env('HISTORIC_MEDIA_WORKERS_LLM', 1),
            ],
            'orchestration' => [
                'queue' => $historicOrchestrationQueue,
                'workers' => (int) env('HISTORIC_MEDIA_WORKERS_ORCHESTRATION', 1),
            ],
        ],
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
        'prompts' => [
            'sermon' => 'The following speech is a Christian sermon preached at Crockenhill Baptist Church, in the British conservative evangelical tradition.',
            'full_service' => 'The following is a full church service at Crockenhill Baptist Church, in the British conservative evangelical tradition: welcome, hymns and songs, prayers, Bible readings, notices and a sermon.',
        ],
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
        'model' => env('ANALYSIS_MODEL', 'gpt-5.6-terra'),
        'reasoning_effort' => env('ANALYSIS_REASONING_EFFORT', 'low'),
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
    | Video Extraction
    |--------------------------------------------------------------------------
    |
    | Sermon and section clips are extracted with a stream copy, so the output
    | inherits the source bitrate. That is right for the current recording setup
    | (2.6-4.6 Mbps) but not for camera-original historic material, where a
    | 22 Mbps source yields a multi-gigabyte clip carrying fidelity nothing
    | plays: the site serves web video, and the archive is the source file, not
    | the extract.
    |
    | Above `reencode_above_mbps` the extract is re-encoded instead. The default
    | sits in the gap between the legacy camera era and the current OBS era, so
    | ordinary weekly uploads stream-copy byte-identically and only heavyweight
    | material is touched. Set to 0 to always stream copy.
    |
    | Quality is expressed as CRF rather than a target bitrate: a fixed bitrate
    | would inflate an already-small source while degrading it, whereas CRF
    | spends bits only where the picture needs them.
    |
    */
    'video_extraction' => [
        'reencode_above_mbps' => (float) env('VIDEO_EXTRACTION_REENCODE_ABOVE_MBPS', 6.0),
        'reencode_crf' => (int) env('VIDEO_EXTRACTION_REENCODE_CRF', 23),
        'reencode_preset' => env('VIDEO_EXTRACTION_REENCODE_PRESET', 'medium'),
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

    'section_classification' => [
        'prefer_high_confidence_sermon_section' => env('SERVICE_SECTION_PREFER_HIGH_CONFIDENCE_SERMON', true),
        'adjacent_merge_max_gap_seconds' => (int) env('SERVICE_SECTION_ADJACENT_MERGE_MAX_GAP_SECONDS', 2),

        /*
         * The shortest song clip that may publish itself.
         *
         * Below this a clip reaches a reviewer instead. A doxology or a chorus
         * really can be this short, so the rule holds the clip rather than
         * rejecting it. Every historic-video pilot clip that should have been
         * seen ran under a minute; every clip that was fine ran over two.
         */
        'song_minimum_automatic_duration_seconds' => (float) env('SERVICE_SECTION_SONG_MIN_AUTO_DURATION', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reading Reference Validation
    |--------------------------------------------------------------------------
    | Prevents a short closing benediction from being adopted as the paired
    | scripture reading when validating an LLM-proposed service structure.
    */
    'reading_references' => [
        'benediction_max_duration_seconds' => (float) env('READING_REFERENCES_BENEDICTION_MAX_DURATION', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | LLM-First Service Structure Pipeline
    |--------------------------------------------------------------------------
    | Full-service transcription + one-call LLM structure detection, replacing
    | the retired heuristic classification cluster. Shadow mode remains the
    | model-upgrade evaluation path; primary is authoritative.
    */
    'service_structure' => [
        // shadow|primary — primary matches the production pipeline.
        'mode' => env('SERVICE_STRUCTURE_MODE', 'primary'),
        // mock|openai — the ServiceStructureInterface binding.
        'detector' => env('SERVICE_STRUCTURE_DETECTOR', 'mock'),
        // Owns the sermon-vs-children's-talk judgement, so it defaults to the
        // flagship reasoning model.
        'model' => env('SERVICE_STRUCTURE_MODEL', 'gpt-5.6-sol'),
        'reasoning_effort' => env('SERVICE_STRUCTURE_REASONING_EFFORT', 'medium'),
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
        'transcript_recovery' => [
            'enabled' => env('SERVICE_TRANSCRIPT_RECOVERY_ENABLED', true),
            'min_repeated_cues' => (int) env('SERVICE_TRANSCRIPT_RECOVERY_MIN_REPEATED_CUES', 6),
            'min_window_seconds' => (float) env('SERVICE_TRANSCRIPT_RECOVERY_MIN_WINDOW_SECONDS', 120),
            'max_phrase_words' => (int) env('SERVICE_TRANSCRIPT_RECOVERY_MAX_PHRASE_WORDS', 12),
            'max_gap_seconds' => (float) env('SERVICE_TRANSCRIPT_RECOVERY_MAX_GAP_SECONDS', 60),
        ],
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
        'lyrics_threshold' => (float) env('SONG_MATCHING_LYRICS_THRESHOLD', 0.6),
        // Matches at or above this confidence rewrite the section's display
        // title to the catalogued song title; below it only the match record
        // is stored and the heard text stays on display.
        'title_writeback_min_confidence' => (float) env('SONG_MATCHING_TITLE_WRITEBACK_MIN_CONFIDENCE', 0.75),
        'ocr_enabled' => env('SONG_MATCHING_OCR_ENABLED', true),
        'ocr_model' => env('SONG_MATCHING_OCR_MODEL', 'gpt-5.4-mini'),
        'ocr_reasoning_effort' => env('SONG_MATCHING_OCR_REASONING_EFFORT', 'minimal'),
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
            // A long trailing section is review-worthy only when another timed
            // service item corroborates a separate boundary; duration alone is
            // never a sermon-side review trigger.
            'long_tail_review_seconds' => 120,
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
        'song_boundary' => [
            // A gap beyond this point is too far into the candidate to trust as
            // a harmless framing pause. It is still kept in the inclusive clip
            // and sent to review; no pre-bulk recut is attempted.
            'max_spoken_framing_seconds' => (float) env('SERVICE_SECTION_SONG_MAX_SPOKEN_FRAMING_SECONDS', 30),
            'minimum_wordless_gap_seconds' => (float) env('SERVICE_SECTION_SONG_MIN_WORDLESS_GAP_SECONDS', 3),
            'minimum_rms_active_ratio' => (float) env('SERVICE_SECTION_SONG_MIN_RMS_ACTIVE_RATIO', 0.25),
            'trailing_evidence_window_seconds' => (float) env('SERVICE_SECTION_SONG_TRAILING_EVIDENCE_WINDOW_SECONDS', 60),
            // Measured as the span from the end of the trailing wordless gap to the
            // end of the candidate. A benediction arrives as several short cues, so
            // the final cue's own length is not the quantity of interest.
            'minimum_trailing_content_seconds' => (float) env('SERVICE_SECTION_SONG_MIN_TRAILING_CONTENT_SECONDS', 10),
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

];
