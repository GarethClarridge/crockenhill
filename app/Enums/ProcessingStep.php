<?php

declare(strict_types=1);

namespace App\Enums;

use App\Services\Processing\ProcessingPhaseRegistry;

/**
 * Canonical processing step names and their associated progress percentages.
 *
 * These values are the authoritative source for:
 *   - The `current_step` column written by jobs and services.
 *   - Progress percentage mapping used by the API and Livewire status views.
 */
enum ProcessingStep: string
{
    // -------------------------------------------------------------------------
    // Initiation steps (all types)
    // -------------------------------------------------------------------------
    case AudioProcessingInitiated = 'audio_processing_initiated';
    case VideoProcessingInitiated = 'video_processing_initiated';
    case InitiatedFromLivestream = 'initiated_from_livestream';

    // -------------------------------------------------------------------------
    // Validation / preparation
    // -------------------------------------------------------------------------
    case Validating = 'validating';
    case AudioValidationComplete = 'audio_validation_complete';
    case VideoValidationComplete = 'video_validation_complete';

    // -------------------------------------------------------------------------
    // Livestream-specific pipeline steps
    // -------------------------------------------------------------------------
    case VisualAnalysis = 'visual_analysis';
    case RmsGeneration = 'rms_generation';
    case Segmentation = 'segmentation';
    case Segmenting = 'segmenting';
    case AnalyzingSegments = 'analyzing_segments';
    case ClassifyingSections = 'classifying_sections';
    case SectionClassificationComplete = 'section_classification_complete';
    case SectionClassificationSkipped = 'section_classification_skipped';
    case TranscribeSpeechSegments = 'transcribe_speech_segments';
    case TranscribeFullService = 'transcribe_full_service';
    case DetectServiceStructure = 'detect_service_structure';
    case ClassifySpeechSections = 'classify_speech_sections';
    case ProjectLivestreamServiceStructure = 'project_livestream_service_structure';
    case AlignWithOos = 'align_with_oos';
    case ResolveReadingReferences = 'resolve_reading_references';
    case MatchSongsFromTranscript = 'match_songs_from_transcript';
    case ReclassifyIntroOutro = 'reclassify_intro_outro';
    case Extraction = 'extraction';
    case ExtractingSermon = 'extracting_sermon';
    case ExtractionComplete = 'extraction_complete';

    // -------------------------------------------------------------------------
    // Audio/video pipeline steps
    // -------------------------------------------------------------------------
    case ExtractingAudio = 'extracting_audio';
    case AudioExtractionComplete = 'audio_extraction_complete';

    // -------------------------------------------------------------------------
    // Sermon creation
    // -------------------------------------------------------------------------
    case SermonCreation = 'sermon_creation';
    case CreatingSermon = 'creating_sermon';
    case CreatingSermonRecord = 'creating_sermon_record';
    case SermonRecordCreated = 'sermon_record_created';

    // -------------------------------------------------------------------------
    // Transcription
    // -------------------------------------------------------------------------
    case TranscribingAudio = 'transcribing_audio';
    case TranscriptionCompleted = 'transcription_completed';
    case Transcription = 'transcription';

    // -------------------------------------------------------------------------
    // AI analysis
    // -------------------------------------------------------------------------
    case AnalyzingTranscript = 'analyzing_transcript';
    case AiAnalysisCompleted = 'ai_analysis_completed';
    case AiAnalysisFallback = 'ai_analysis_fallback';

    // -------------------------------------------------------------------------
    // Post-processing
    // -------------------------------------------------------------------------
    case GeneratingThumbnail = 'generating_thumbnail';
    case PreparingSectionPublicationCandidates = 'preparing_section_publication_candidates';
    case UpdatingSermonRecord = 'updating_sermon_record';
    case Cleanup = 'cleanup';

    // -------------------------------------------------------------------------
    // Notifications
    // -------------------------------------------------------------------------
    case SendingNotification = 'sending_notification';
    case NotificationSent = 'notification_sent';
    case NotificationSkipped = 'notification_skipped';
    case NotificationFailed = 'notification_failed';
    case NotificationFailedPermanently = 'notification_failed_permanently';

    // -------------------------------------------------------------------------
    // Terminal / error states
    // -------------------------------------------------------------------------
    case Completed = 'completed';
    case CompletedWithBasicData = 'completed_with_basic_data';
    case JobChainFailed = 'job_chain_failed';
    case CreatingSermonRecordFailed = 'creating_sermon_record_failed';
    case TranscribingAudioFailed = 'transcribing_audio_failed';
    case AnalyzingTranscriptFailed = 'analyzing_transcript_failed';
    case UpdatingSermonRecordFailed = 'updating_sermon_record_failed';
    case ManualReviewRequired = 'manual_review_required';
    case ManualReviewConfirmed = 'manual_review_confirmed';
    case Cancelled = 'cancelled';
    case Preparing = 'preparing';
    case RestartingFromBeginning = 'restarting_from_beginning';
    case SermonSubmitted = 'sermon_submitted';

    // -------------------------------------------------------------------------
    // Progress mapping
    // -------------------------------------------------------------------------

    /**
     * Return the progress percentage for this step when processing is active.
     *
     * Terminal states (completed/failed/cancelled) are handled separately by
     * StandardProcessingResponse::calculateProgress() before this is called.
     */
    public function progressPercentage(): int
    {
        return self::progressForStep($this->value);
    }

    /**
     * Try to resolve a step from a raw string value, returning null for unknown steps.
     */
    public static function tryFromString(string $step): ?self
    {
        // Handle dynamic initiated_from_livestream:<id> values
        if (str_starts_with($step, 'initiated_from_livestream:')) {
            return self::InitiatedFromLivestream;
        }

        return self::tryFrom($step);
    }

    /**
     * Return the progress percentage for a raw step string, falling back to 50 for unknowns.
     */
    public static function progressForStep(?string $step, ?MediaType $mediaType = null): int
    {
        if (! function_exists('app')) {
            return 50;
        }

        return app(ProcessingPhaseRegistry::class)->progressForStep($step, $mediaType);
    }
}
