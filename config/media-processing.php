<?php

use App\Services\ChurchService\SectionPublication\SongPublicationHandler;
use App\Services\ChurchService\SectionPublication\TalkPublicationHandler;

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
        // Operator pause (2026-09-23): keep every run's temporary files, including
        // its source working copy, so restaged historic sources survive the repair
        // passes. Remove this switch once those passes are done.
        'pause_temporary_file_cleanup' => (bool) env('MEDIA_PAUSE_TEMP_FILE_CLEANUP', false),
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
    | Audio Classification
    |--------------------------------------------------------------------------
    |
    | Where the music/speech classifier runs. Unset (production): the app runs
    | scripts/classify_audio.py itself, on CPU. Locally it points at
    | scripts/classify_audio_server.py on the Mac, which uses the GPU and is
    | ~11x faster with identical output, as transcription points at
    | whisper-server on :2022.
    |
    */
    'audio_classifier' => [
        'url' => env('AUDIO_CLASSIFIER_URL'),
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
        // Measured on 300 s of 1080p speech from the historic corpus, ten cores:
        // medium 67.5 s at SSIM 0.99507, faster 36.6 s at 0.99431, veryfast 25.1 s
        // at 0.99306. `faster` is 1.85x the throughput for eight ten-thousandths
        // of SSIM — invisible on a static camera pointed at a pulpit — and lands a
        // marginally smaller file. Roughly half the historic corpus re-encodes
        // (VP9, or above the bitrate threshold), so this is the setting that
        // decides how long a bulk pass takes.
        'reencode_preset' => env('VIDEO_EXTRACTION_REENCODE_PRESET', 'faster'),
        // How far before the cut a stream copy's coarse input seek lands. It only
        // has to clear the source GOP so the fine output seek still decides where
        // the cut falls; see VideoExtractionService::streamCopySeekArguments().
        // Zero restores the single output seek.
        'copy_seek_prefix_seconds' => (float) env('VIDEO_EXTRACTION_COPY_SEEK_PREFIX_SECONDS', 30.0),
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
        // A looping decode holds the transcript for review even when the
        // recovery detector above never fires. Measured against the 446-run
        // historic corpus, then source-audited against the 32-run short-loop
        // register on 2026-09-20: 29 are corrupt stored text and three are
        // genuine rhetoric. Four repeats and twelve repeated words are the
        // conservative review boundary; source re-decode settles the genuine
        // cases. The words-per-minute ceiling is a backstop for near-repeats the
        // verbatim rule cannot see; relaxing it towards 250 starts returning
        // ordinary preaching. See ServiceTranscriptRepetitionScreen.
        /*
         * The coverage screen measures the opposite of the repetition screen:
         * not text the audio cannot explain, but audio the text does not
         * account for. It reads the RMS log rather than the transcript's own
         * shape, so it stays sensitive where two decoders would fail together —
         * a loop both of them produce is invisible to their disagreement but
         * still leaves speech energy with no words against it.
         *
         * Read-only: this screen raises no hold. It exists to count what the
         * repetition screen missed (§4.3a H10), not to contain anything.
         */
        'coverage_screen' => [
            'window_seconds' => (float) env('SERVICE_TRANSCRIPT_COVERAGE_WINDOW_SECONDS', 60),
            // A window must be mostly sound before its silence is worth
            // explaining; a pause between items is not a defect.
            'min_sounded_share' => (float) env('SERVICE_TRANSCRIPT_COVERAGE_MIN_SOUNDED_SHARE', 0.75),
            // Half the rate the cadence screen already treats as dense speech,
            // so a window has to be markedly under-transcribed to qualify.
            'max_words_per_minute' => (float) env('SERVICE_TRANSCRIPT_COVERAGE_MAX_WPM', 30),
            // Shorter runs of quiet are ordinary: a held pause, a long prayer
            // gap, a reader finding their place.
            'min_gap_seconds' => (float) env('SERVICE_TRANSCRIPT_COVERAGE_MIN_GAP_SECONDS', 90),
        ],

        'repetition_screen' => [
            'min_repeats' => (int) env('SERVICE_TRANSCRIPT_REPETITION_MIN_REPEATS', 4),
            'min_repeated_words' => (int) env('SERVICE_TRANSCRIPT_REPETITION_MIN_WORDS', 12),
            'min_phrase_words' => (int) env('SERVICE_TRANSCRIPT_REPETITION_MIN_PHRASE_WORDS', 3),
            'short_phrase_max_words' => (int) env('SERVICE_TRANSCRIPT_REPETITION_SHORT_PHRASE_MAX_WORDS', 2),
            'short_phrase_min_repeats' => (int) env('SERVICE_TRANSCRIPT_REPETITION_SHORT_PHRASE_MIN_REPEATS', 16),
            'short_phrase_min_repeated_words' => (int) env('SERVICE_TRANSCRIPT_REPETITION_SHORT_PHRASE_MIN_WORDS', 24),
            'max_phrase_words' => (int) env('SERVICE_TRANSCRIPT_REPETITION_MAX_PHRASE_WORDS', 25),
            'max_words_per_minute' => (float) env('SERVICE_TRANSCRIPT_REPETITION_MAX_WPM', 400),
            'density_window_seconds' => (float) env('SERVICE_TRANSCRIPT_REPETITION_DENSITY_WINDOW_SECONDS', 30),
            'cadence_max_cue_words' => (int) env('SERVICE_TRANSCRIPT_CADENCE_MAX_CUE_WORDS', 10),
            'cadence_min_cues' => (int) env('SERVICE_TRANSCRIPT_CADENCE_MIN_CUES', 4),
            'cadence_interval_seconds' => (float) env('SERVICE_TRANSCRIPT_CADENCE_INTERVAL_SECONDS', 30),
            'cadence_tolerance_seconds' => (float) env('SERVICE_TRANSCRIPT_CADENCE_TOLERANCE_SECONDS', 0.6),
            'cadence_flank_seconds' => (float) env('SERVICE_TRANSCRIPT_CADENCE_FLANK_SECONDS', 30),
            'cadence_min_flank_wpm' => (float) env('SERVICE_TRANSCRIPT_CADENCE_MIN_FLANK_WPM', 60),
            // Context either side of a block when re-decoding it. A decoder
            // handed 24 seconds with no lead-in has nothing to work from and
            // invents confidently, which is the failure being repaired. Only the
            // block's own span is written back; the padding is context.
            'recovery_padding_seconds' => (float) env('SERVICE_TRANSCRIPT_REPETITION_RECOVERY_PADDING_SECONDS', 30),
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
            // A recording that is essentially one unbroken block of speech is a
            // sermon-only capture, not a whole service. The 20-minute floor in
            // SermonCandidateConfidenceService exists to pick the sermon out of a
            // service's other speech (notices, prayers, readings); in a sermon-only
            // capture there is nothing to pick between, so that floor only measures
            // how long the sermon itself ran. Measured on the historic corpus the
            // two populations are widely separated: sermon-only captures cover
            // 92.5-98.0% of their recording, whole-service captures 31.4-67.0%.
            // 0.90 sits inside that empty gap.
            'sermon_only_coverage_ratio' => 0.90,
            // What the sermon in such a recording typically runs to, per service.
            // A shorter one is unusual but perfectly legitimate — a carol service
            // may carry an eight-minute sermon — so it is never rejected on length.
            // It is routed to manual review instead, which is the only way to tell
            // a genuinely short sermon from something that is not a sermon at all
            // (the 2023-07-16 children's talk is 405s and covers its whole
            // recording). A service we cannot name takes the stricter figure, so
            // the doubt is resolved towards review.
            'sermon_only_typical_minimum_seconds' => [
                'morning' => 1500,
                'evening' => 900,
            ],
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

        /*
         * The detector measures how much of the whole recording has dead
         * picture (frozen or black), in one pass at one frame a second.
         */
        'probe' => [
            'frames_per_second' => (float) env('SERMON_VIDEO_QUALITY_PROBE_FPS', 1.0),
            'freeze_noise_db' => (float) env('SERMON_VIDEO_QUALITY_FREEZE_NOISE_DB', -60.0),
            'freeze_min_seconds' => (float) env('SERMON_VIDEO_QUALITY_FREEZE_MIN_SECONDS', 20.0),
            'black_min_seconds' => (float) env('SERMON_VIDEO_QUALITY_BLACK_MIN_SECONDS', 5.0),
            'black_pixel_threshold' => (float) env('SERMON_VIDEO_QUALITY_BLACK_PIXEL_THRESHOLD', 0.10),
            'timeout_seconds' => (int) env('SERMON_VIDEO_QUALITY_PROBE_TIMEOUT', 900),
        ],
        'thresholds' => [
            /*
             * Operator ruling 2026-09-22: a video is released whole or not at
             * all, never trimmed. It is released when at least this share of it
             * has usable picture, and flagged as having video issues when any of
             * it is dead; below this share it is hidden and the sermon is
             * released audio-only.
             */
            'release_usable_share' => (float) env('SERMON_VIDEO_QUALITY_RELEASE_USABLE_SHARE', 0.75),
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
            'short_talk' => TalkPublicationHandler::class,
            'song' => SongPublicationHandler::class,
            // 'sermon' => \App\Services\ChurchService\SectionPublication\TalkPublicationHandler::class, // future
        ],
        'require_high_confidence' => env('SERVICE_SECTION_PUBLISH_REQUIRE_HIGH_CONFIDENCE', true),
        'retain_unpublished_hours' => (int) env('SERVICE_SECTION_RETAIN_UNPUBLISHED_HOURS', 48),
        'song_boundary' => [
            // A gap beyond this point is too far into the candidate to trust as
            // a harmless framing pause. It is still kept in the inclusive clip
            // and sent to review; no pre-bulk recut is attempted.
            'max_spoken_framing_seconds' => (float) env('SERVICE_SECTION_SONG_MAX_SPOKEN_FRAMING_SECONDS', 30),
            // Below this, the "framing" is too short to be anyone introducing anything:
            // a trailing "Amen" caught at the candidate edge, or the leader's last few
            // words running into the first sung line. Measured over the thirteen clips
            // the gate held on 4 September, both false positives sat at 1.0s and 1.7s
            // while the shortest genuine introduction was 11.3s, so a floor anywhere in
            // that decade separates them; 3s is the conservative end of it.
            'min_spoken_framing_seconds' => (float) env('SERVICE_SECTION_SONG_MIN_SPOKEN_FRAMING_SECONDS', 3),
            'minimum_wordless_gap_seconds' => (float) env('SERVICE_SECTION_SONG_MIN_WORDLESS_GAP_SECONDS', 3),
            'minimum_rms_active_ratio' => (float) env('SERVICE_SECTION_SONG_MIN_RMS_ACTIVE_RATIO', 0.25),
            // The share of a song section its repetition blocks must cover before the clip is
            // withheld for review. A looping transcript claims text the audio did not produce,
            // so it cannot evidence which song was sung or where it began. Measured over the
            // corpus on 2026-09-16: 63 song sections reach half or more and 85 sit between a
            // fifth and a half, so this is a choice about what may publish itself unreviewed
            // rather than a natural boundary; the 85 below it are recorded and unaddressed.
            'looped_transcript_minimum_share' => (float) env('SERVICE_SECTION_SONG_LOOPED_TRANSCRIPT_MIN_SHARE', 0.5),
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
