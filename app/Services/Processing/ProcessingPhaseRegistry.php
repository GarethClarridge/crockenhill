<?php

declare(strict_types=1);

namespace App\Services\Processing;

use App\Enums\MediaType;
use App\Enums\ProcessingStep;
use App\Jobs\AnalyzeSegments;
use App\Jobs\AssessSermonVideoQuality;
use App\Jobs\CleanupTemporaryFiles;
use App\Jobs\CreateSermonRecord;
use App\Jobs\CreateSermonTranscriptFromService;
use App\Jobs\DetectServiceStructure;
use App\Jobs\EnhanceAudio;
use App\Jobs\ExtractAudioFromVideo;
use App\Jobs\ExtractSermon;
use App\Jobs\GenerateRmsLog;
use App\Jobs\GenerateThumbnail;
use App\Jobs\MatchSongsFromTranscript;
use App\Jobs\PrepareSectionPublicationCandidates;
use App\Jobs\ProcessTranscriptWithAI;
use App\Jobs\ProjectLivestreamServiceStructure;
use App\Jobs\SendCompletionNotification;
use App\Jobs\SubmitToProcessing;
use App\Jobs\TranscribeAudio;
use App\Jobs\TranscribeFullService;
use App\Jobs\ValidateAudioFile;
use App\Jobs\ValidateVideoFile;
use App\Models\MediaProcessingLog;

/**
 * Registry for retryable media-processing phases.
 *
 * Phase offsets and progress are derived from the pipeline builder. The phase
 * definitions therefore only describe the irregular retry behaviour and the
 * canonical step written by the owning job.
 *
 * @phpstan-type ProcessingPhase array<string, mixed>
 * @phpstan-type RetryPlan array<string, mixed>
 * @phpstan-type ChainRetryPlan array<string, mixed>
 * @phpstan-type ManualReviewPlan array<string, mixed>
 */
class ProcessingPhaseRegistry
{
    /** @var array<string, list<class-string>> */
    private array $jobClassCache = [];

    public function __construct(
        private readonly ProcessingPipelineBuilder $pipelineBuilder,
    ) {}

    /**
     * @return list<ProcessingPhase>
     */
    public function phasesForPipeline(string $pipeline): array
    {
        $definitions = $this->phaseDefinitionsForPipeline($pipeline);

        $jobClasses = $this->jobClassesForPipeline($pipeline);
        $lastOffset = max(1, count($jobClasses) - 1);

        return array_map(function (array $phase) use ($jobClasses, $lastOffset): array {
            $offset = array_search($phase['anchor_job'], $jobClasses, true);
            $resolvedOffset = $offset === false ? null : $offset;

            return [
                ...$phase,
                'job_offset' => $resolvedOffset,
                'progress' => isset($phase['progress'])
                    ? $phase['progress']
                    : ($resolvedOffset === null
                    ? ($phase['retry_action'] ?? null) === 'restart_livestream' ? 10 : 50
                    : min(95, 10 + (int) round(($resolvedOffset / $lastOffset) * 85))),
            ];
        }, $definitions);
    }

    public function progressForStep(?string $step, ?MediaType $mediaType = null): int
    {
        $step = $this->canonicalStep($step);

        if ($step === null) {
            return 50;
        }

        $pipelines = ['audio', 'video', 'video_auto_trim', 'livestream'];

        if ($mediaType !== null) {
            $preferred = $this->pipelineForMediaType($mediaType);
            $pipelines = array_values(array_unique([$preferred, ...$pipelines]));
        }

        foreach ($pipelines as $pipeline) {
            $progress = $this->progressForPipelineStep($pipeline, $step);

            if ($progress !== null) {
                return $progress;
            }
        }

        return 50;
    }

    public function progressForLog(MediaProcessingLog $processingLog): int
    {
        /** @var 'audio'|'video'|'video_auto_trim'|'livestream' $pipeline */
        $pipeline = $processingLog->processingPipelineProfile();
        $step = $this->canonicalStep($processingLog->current_step);

        foreach ($this->phasesForPipeline($pipeline) as $phase) {
            if ($phase['step'] === $step) {
                return $phase['progress'];
            }
        }

        return $this->progressForStep($processingLog->current_step, $processingLog->processing_type);
    }

    /** @return RetryPlan */
    public function retryPlanFor(MediaProcessingLog $processingLog): array
    {
        $step = $this->canonicalStep($processingLog->current_step);

        if ($step === 'preparing') {
            return $this->manualReviewPlan(
                'early_processing_failure',
                sprintf(
                    'Early processing failure detected. Source type: %s. File may need to be re-uploaded. Original filename: %s.',
                    $processingLog->processing_type->value,
                    $processingLog->original_filename,
                ),
            );
        }

        if ($step === 'sermon_creation_failed') {
            return $this->manualReviewPlan(
                'sermon_record_creation_failed',
                'Failed during sermon record creation — requires manual intervention.',
            );
        }

        $pipeline = $processingLog->processingPipelineProfile();
        $phase = $this->phaseForPipelineStep($pipeline, $step);

        if ($phase !== null && $phase['job_offset'] !== null) {
            return [
                'action' => $phase['retry_action'] ?? 'dispatch_chain',
                'pipeline' => $pipeline,
                'job_offset' => $phase['job_offset'],
                'rerun_strategy' => $phase['rerun_strategy'] ?? 'safe_to_rerun',
                'reset_scope' => $phase['reset_scope'] ?? 'none',
            ];
        }

        if ($processingLog->processing_type === MediaType::Livestream) {
            return ['action' => 'restart_livestream'];
        }

        return $this->manualReviewPlan(
            'unknown_processing_step',
            sprintf('Unknown processing step: %s.', $processingLog->current_step ?? 'unknown'),
        );
    }

    /** @return array{action: 'manual_review', reason_code: string, reason_message: string} */
    private function manualReviewPlan(string $reasonCode, string $reasonMessage): array
    {
        return ['action' => 'manual_review', 'reason_code' => $reasonCode, 'reason_message' => $reasonMessage];
    }

    private function canonicalStep(?string $step): ?string
    {
        if (blank($step)) {
            return null;
        }

        $canonicalStep = ProcessingStep::canonicalize($step);

        return match ($canonicalStep) {
            'creating_sermon', 'creating_sermon_record', 'sermon_record_created' => 'sermon_creation',
            'transcribing_audio_failed', 'transcription_completed', 'transcription' => 'transcribing_audio',
            'analyzing_transcript_failed', 'ai_analysis_completed', 'ai_analysis_fallback' => 'analyzing_transcript',
            'audio_enhancement_complete', 'audio_enhancement_skipped' => 'audio_enhancement',
            'extracting_sermon' => 'extraction',
            'manual_review_confirmed' => 'manual_review_required',
            'notification_skipped', 'notification_failed', 'notification_failed_permanently' => 'notification_sent',
            'restarting_from_beginning' => 'livestream_processing_initiated',
            default => $canonicalStep,
        };
    }

    private function pipelineForMediaType(MediaType $mediaType): string
    {
        return match ($mediaType) {
            MediaType::Audio => 'audio',
            MediaType::Video => 'video',
            MediaType::Livestream => 'livestream',
        };
    }

    /** @return ProcessingPhase|null */
    private function phaseForPipelineStep(string $pipeline, ?string $step): ?array
    {
        foreach ($this->phasesForPipeline($pipeline) as $phase) {
            if ($phase['step'] === $step) {
                return $phase;
            }
        }

        return null;
    }

    /** @return list<class-string> */
    private function jobClassesForPipeline(string $pipeline): array
    {
        if (isset($this->jobClassCache[$pipeline])) {
            return $this->jobClassCache[$pipeline];
        }

        if ($pipeline === 'livestream') {
            return $this->jobClassCache[$pipeline] = $this->pipelineBuilder->livestreamChainJobClasses();
        }

        $log = new MediaProcessingLog;
        $jobs = match ($pipeline) {
            'audio' => $this->pipelineBuilder->buildAudioPipeline($log),
            'video' => $this->pipelineBuilder->buildDirectVideoPipeline($log),
            'video_auto_trim' => $this->pipelineBuilder->buildAutoTrimVideoPipeline($log),
            default => [],
        };

        return $this->jobClassCache[$pipeline] = array_values(array_map(
            static fn (object $job): string => $job::class,
            $jobs,
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function phaseDefinitionsForPipeline(string $pipeline): array
    {
        return match ($pipeline) {
            'audio' => $this->audioPhases(),
            'video' => $this->videoPhases(),
            'video_auto_trim' => $this->videoAutoTrimPhases(),
            'livestream' => $this->livestreamPhases(),
            default => [],
        };
    }

    private function progressForPipelineStep(string $pipeline, ?string $step): ?int
    {
        foreach ($this->phaseDefinitionsForPipeline($pipeline) as $phase) {
            if ($phase['step'] === $step) {
                return isset($phase['progress']) ? (int) $phase['progress'] : null;
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    private function audioPhases(): array
    {
        return [
            $this->phase('initiated_from_livestream', ValidateAudioFile::class, 'initiated_from_livestream', progress: 10),
            $this->phase('audio_initiated', ValidateAudioFile::class, 'audio_processing_initiated', progress: 10),
            $this->phase('validate_audio', ValidateAudioFile::class, 'validating', progress: 15),
            $this->phase('create_sermon_record', CreateSermonRecord::class, 'sermon_creation', progress: 60),
            $this->phase('transcribe_audio', TranscribeAudio::class, 'transcribing_audio', progress: 70),
            $this->phase('analyze_transcript', ProcessTranscriptWithAI::class, 'analyzing_transcript', progress: 85),
            $this->phase('send_notification', SendCompletionNotification::class, 'sending_notification', progress: 92),
            $this->phase('notification_complete', SendCompletionNotification::class, 'notification_sent', progress: 92),
            $this->phase('cleanup', CleanupTemporaryFiles::class, 'cleanup', progress: 95),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function videoPhases(): array
    {
        return [
            $this->phase('video_initiated', ValidateVideoFile::class, 'video_processing_initiated', progress: 10),
            $this->phase('validate_video', ValidateVideoFile::class, 'validating', progress: 15),
            $this->phase('extract_audio', ExtractAudioFromVideo::class, 'extracting_audio', progress: 25),
            $this->phase('create_sermon_record', CreateSermonRecord::class, 'sermon_creation', progress: 60),
            $this->phase('transcribe_audio', TranscribeAudio::class, 'transcribing_audio', progress: 70),
            $this->phase('analyze_transcript', ProcessTranscriptWithAI::class, 'analyzing_transcript', progress: 85),
            $this->phase('assess_video_quality', AssessSermonVideoQuality::class, 'assessing_video_quality', progress: 87),
            $this->phase('generate_thumbnail', GenerateThumbnail::class, 'generating_thumbnail', progress: 89),
            $this->phase('send_notification', SendCompletionNotification::class, 'sending_notification', progress: 92),
            $this->phase('notification_complete', SendCompletionNotification::class, 'notification_sent', progress: 92),
            $this->phase('cleanup', CleanupTemporaryFiles::class, 'cleanup', progress: 95),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function videoAutoTrimPhases(): array
    {
        return [
            $this->phase('video_initiated', ValidateVideoFile::class, 'video_processing_initiated', progress: 10),
            $this->phase('validate_video', ValidateVideoFile::class, 'validating', progress: 15),
            $this->phase('rms_generation', GenerateRmsLog::class, 'rms_generation', progress: 20),
            $this->phase('analyze_segments', AnalyzeSegments::class, 'segmentation', progress: 30, resetScope: 'analyze_segments', rerunStrategy: 'targeted_reset'),
            $this->phase('legacy_analyze_segments', AnalyzeSegments::class, 'analyzing_segments', progress: 40),
            $this->phase('transcribe_full_service', TranscribeFullService::class, 'transcribe_full_service'),
            $this->phase('detect_service_structure', DetectServiceStructure::class, 'detect_service_structure'),
            $this->phase('manual_review', ExtractSermon::class, 'manual_review_required'),
            $this->phase('extract_sermon', ExtractSermon::class, 'extraction', progress: 57),
            $this->phase('enhance_audio', EnhanceAudio::class, 'audio_enhancement'),
            $this->phase('create_sermon_record', CreateSermonRecord::class, 'sermon_creation', progress: 60),
            $this->phase('transcribe_audio', CreateSermonTranscriptFromService::class, 'transcribing_audio', progress: 70),
            $this->phase('analyze_transcript', ProcessTranscriptWithAI::class, 'analyzing_transcript', progress: 85),
            $this->phase('assess_video_quality', AssessSermonVideoQuality::class, 'assessing_video_quality', progress: 87),
            $this->phase('generate_thumbnail', GenerateThumbnail::class, 'generating_thumbnail', progress: 89),
            $this->phase('send_notification', SendCompletionNotification::class, 'sending_notification', progress: 92),
            $this->phase('notification_complete', SendCompletionNotification::class, 'notification_sent', progress: 92),
            $this->phase('cleanup', CleanupTemporaryFiles::class, 'cleanup', progress: 95),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function livestreamPhases(): array
    {
        return [
            $this->phase('parallel_start', GenerateRmsLog::class, 'livestream_processing_initiated', progress: 10, retryAction: 'restart_livestream', rerunStrategy: 'full_restart'),
            $this->phase('rms_generation', GenerateRmsLog::class, 'rms_generation', progress: 20, retryAction: 'restart_livestream', rerunStrategy: 'full_restart'),
            $this->phase('analyze_segments', AnalyzeSegments::class, 'segmentation', progress: 30, retryAction: 'dispatch_livestream_chain', resetScope: 'analyze_segments', rerunStrategy: 'targeted_reset'),
            $this->phase('legacy_analyze_segments', AnalyzeSegments::class, 'analyzing_segments', progress: 40),
            $this->phase('transcribe_full_service', TranscribeFullService::class, 'transcribe_full_service', retryAction: 'dispatch_livestream_chain'),
            $this->phase('detect_service_structure', DetectServiceStructure::class, 'detect_service_structure', retryAction: 'dispatch_livestream_chain'),
            $this->phase('project_livestream_service_structure', ProjectLivestreamServiceStructure::class, 'project_livestream_service_structure', retryAction: 'dispatch_livestream_chain'),
            $this->phase('match_songs_from_transcript', MatchSongsFromTranscript::class, 'match_songs_from_transcript', retryAction: 'dispatch_livestream_chain'),
            $this->phase('manual_review', ExtractSermon::class, 'manual_review_required'),
            $this->phase('extract_sermon', ExtractSermon::class, 'extraction', progress: 57, retryAction: 'dispatch_livestream_chain'),
            $this->phase('submit_to_processing', SubmitToProcessing::class, 'sermon_submitted', retryAction: 'dispatch_livestream_chain', resetScope: 'submit_to_processing', rerunStrategy: 'targeted_reset'),
            $this->phase('transcription', CreateSermonTranscriptFromService::class, 'transcribing_audio', progress: 70, retryAction: 'dispatch_livestream_chain'),
            $this->phase('analysis', ProcessTranscriptWithAI::class, 'analyzing_transcript', progress: 85, retryAction: 'dispatch_livestream_chain'),
            $this->phase('assess_video_quality', AssessSermonVideoQuality::class, 'assessing_video_quality', progress: 87, retryAction: 'dispatch_livestream_chain'),
            $this->phase('thumbnail', GenerateThumbnail::class, 'generating_thumbnail', progress: 89, retryAction: 'dispatch_livestream_chain'),
            $this->phase('prepare_section_publication_candidates', PrepareSectionPublicationCandidates::class, 'preparing_section_publication_candidates', progress: 84, retryAction: 'dispatch_livestream_chain'),
            $this->phase('send_notification', SendCompletionNotification::class, 'sending_notification', progress: 92, retryAction: 'dispatch_livestream_chain'),
            $this->phase('notification_complete', SendCompletionNotification::class, 'notification_sent', progress: 92, retryAction: 'dispatch_livestream_chain'),
            $this->phase('cleanup', CleanupTemporaryFiles::class, 'cleanup', progress: 95, retryAction: 'dispatch_livestream_chain'),
        ];
    }

    /**
     * @param  class-string  $anchorJob
     * @return array<string, mixed>
     */
    private function phase(
        string $key,
        string $anchorJob,
        string $step,
        ?int $progress = null,
        ?string $retryAction = null,
        ?string $rerunStrategy = null,
        ?string $resetScope = null,
    ): array {
        $phase = [
            'key' => $key,
            'anchor_job' => $anchorJob,
            'step' => $step,
        ];

        if ($progress !== null) {
            $phase['progress'] = $progress;
        }

        if ($retryAction !== null) {
            $phase['retry_action'] = $retryAction;
        }

        if ($rerunStrategy !== null) {
            $phase['rerun_strategy'] = $rerunStrategy;
        }

        if ($resetScope !== null) {
            $phase['reset_scope'] = $resetScope;
        }

        return $phase;
    }
}
