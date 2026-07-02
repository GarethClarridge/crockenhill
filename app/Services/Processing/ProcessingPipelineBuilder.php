<?php

declare(strict_types=1);

namespace App\Services\Processing;

use App\Enums\ServiceStructureMode;
use App\Jobs\AlignWithOos;
use App\Jobs\AnalyzeSegments;
use App\Jobs\AssessSermonVideoQuality;
use App\Jobs\ClassifyServiceSections;
use App\Jobs\ClassifySpeechSections;
use App\Jobs\CleanupTemporaryFiles;
use App\Jobs\CreateSermonRecord;
use App\Jobs\DetectServiceStructure;
use App\Jobs\EnhanceAudio;
use App\Jobs\ExtractAudioFromVideo;
use App\Jobs\ExtractSermon;
use App\Jobs\GenerateRmsLog;
use App\Jobs\GenerateThumbnail;
use App\Jobs\IdentifySpeaker;
use App\Jobs\MatchSongsFromTranscript;
use App\Jobs\PerformVisualAnalysis;
use App\Jobs\PrepareSectionPublicationCandidates;
use App\Jobs\ProcessTranscriptWithAI;
use App\Jobs\ProjectLivestreamServiceStructure;
use App\Jobs\ReclassifyIntroOutroSections;
use App\Jobs\ResolveReadingReferences;
use App\Jobs\SendCompletionNotification;
use App\Jobs\SubmitToProcessing;
use App\Jobs\TranscribeAudio;
use App\Jobs\TranscribeFullService;
use App\Jobs\TranscribeSpeechSegments;
use App\Jobs\ValidateAudioFile;
use App\Jobs\ValidateVideoFile;
use App\Models\MediaProcessingLog;

/**
 * ProcessingPipelineBuilder - Unified job chains for all media processing types
 *
 * The livestream and reclassification chains branch on
 * `media-processing.service_structure.mode` (ServiceStructureMode):
 * off keeps the heuristic chains byte-identical, shadow appends the LLM
 * transcript + detection jobs after the heuristic cluster (metadata-only),
 * and primary replaces the heuristic classification cluster with the LLM
 * path. Post-review chains are mode-independent so the operator escape
 * hatch always works; the auto-trim pipeline keeps the heuristic path until
 * primary proves out on livestreams.
 */
class ProcessingPipelineBuilder
{
    /**
     * Build job pipeline for audio processing
     *
     * @return array<int, object>
     */
    public function buildAudioPipeline(MediaProcessingLog $log): array
    {
        return [
            new ValidateAudioFile($log),
            new EnhanceAudio($log),
            new CreateSermonRecord($log),
            new IdentifySpeaker($log),
            new TranscribeAudio($log),
            new ProcessTranscriptWithAI($log),
            new SendCompletionNotification($log),
            new CleanupTemporaryFiles($log),
        ];
    }

    /**
     * Build job pipeline for direct video processing
     *
     * @return array<int, object>
     */
    public function buildDirectVideoPipeline(MediaProcessingLog $log): array
    {
        return [
            new ValidateVideoFile($log),
            new ExtractAudioFromVideo($log),
            new EnhanceAudio($log),
            new CreateSermonRecord($log),
            new IdentifySpeaker($log),
            new TranscribeAudio($log),
            new ProcessTranscriptWithAI($log),
            new AssessSermonVideoQuality($log),
            new GenerateThumbnail($log),
            new SendCompletionNotification($log),
            new CleanupTemporaryFiles($log),
        ];
    }

    /**
     * Build job pipeline for sermon video uploads that should be auto-trimmed
     * before entering the standard sermon-processing flow.
     *
     * @return array<int, object>
     */
    public function buildAutoTrimVideoPipeline(MediaProcessingLog $log): array
    {
        return [
            new ValidateVideoFile($log),
            new GenerateRmsLog($log),
            new AnalyzeSegments($log),
            new ClassifyServiceSections($log),
            new TranscribeSpeechSegments($log),
            new ClassifySpeechSections($log),
            new ReclassifyIntroOutroSections($log),
            new ExtractSermon($log),
            new EnhanceAudio($log),
            new CreateSermonRecord($log),
            new IdentifySpeaker($log),
            new TranscribeAudio($log),
            new ProcessTranscriptWithAI($log),
            new AssessSermonVideoQuality($log),
            new GenerateThumbnail($log),
            new SendCompletionNotification($log),
            new CleanupTemporaryFiles($log),
        ];
    }

    /**
     * Jobs to run in parallel at the start of the livestream pipeline.
     * Always includes RMS generation; includes visual analysis if enabled.
     *
     * @return non-empty-list<object>
     */
    public function buildLivestreamParallelJobs(MediaProcessingLog $log): array
    {
        $jobs = [];

        if (config('media-processing.visual_analysis.enabled', true)) {
            $jobs[] = new PerformVisualAnalysis($log);
        }

        $jobs[] = new GenerateRmsLog($log);

        return $jobs;
    }

    /**
     * Sequential jobs that run after the parallel phase completes.
     *
     * @return non-empty-list<object>
     */
    public function buildLivestreamChainJobs(MediaProcessingLog $log): array
    {
        $mode = ServiceStructureMode::fromConfig();

        if ($mode === ServiceStructureMode::Primary) {
            // AnalyzeSegments is retained: LivestreamSegment rows back the
            // sections' source_segment_ids and the manual segment-confirmation
            // flow. ProjectLivestreamServiceStructure is retained per the
            // Phase 4 audit: it creates/links the canonical ChurchService when
            // no OoS import exists, reading the (now LLM-written) sections.
            return [
                new AnalyzeSegments($log),
                new TranscribeFullService($log),
                new DetectServiceStructure($log),
                new ProjectLivestreamServiceStructure($log),
                new MatchSongsFromTranscript($log),
                new ExtractSermon($log),
                new SubmitToProcessing($log),
                new EnhanceAudio($log),
                new IdentifySpeaker($log),
                new TranscribeAudio($log),
                new ProcessTranscriptWithAI($log),
                new AssessSermonVideoQuality($log),
                new GenerateThumbnail($log),
                new PrepareSectionPublicationCandidates($log),
                new SendCompletionNotification($log),
                new CleanupTemporaryFiles($log),
            ];
        }

        $shadowJobs = $mode === ServiceStructureMode::Shadow
            ? [new TranscribeFullService($log), new DetectServiceStructure($log)]
            : [];

        return [
            new AnalyzeSegments($log),
            new ClassifyServiceSections($log),
            new TranscribeSpeechSegments($log),
            new ClassifySpeechSections($log),
            new ProjectLivestreamServiceStructure($log),
            new AlignWithOos($log),
            new ResolveReadingReferences($log),
            new MatchSongsFromTranscript($log),
            new ReclassifyIntroOutroSections($log),
            // Shadow runs after the full heuristic cluster so its diff
            // compares against the final heuristic output.
            ...$shadowJobs,
            new ExtractSermon($log),
            new SubmitToProcessing($log),
            new EnhanceAudio($log),
            new IdentifySpeaker($log),
            new TranscribeAudio($log),
            new ProcessTranscriptWithAI($log),
            new AssessSermonVideoQuality($log),
            new GenerateThumbnail($log),
            new PrepareSectionPublicationCandidates($log),
            new SendCompletionNotification($log),
            new CleanupTemporaryFiles($log),
        ];
    }

    /**
     * The job classes of the current mode's livestream chain, in order.
     *
     * ProcessingPhaseRegistry resolves its retry offsets from this list, so a
     * mode change can never leave the retry table pointing at the wrong job.
     *
     * @return non-empty-list<class-string>
     */
    public function livestreamChainJobClasses(): array
    {
        return array_map(
            static fn (object $job): string => $job::class,
            $this->buildLivestreamChainJobs(new MediaProcessingLog)
        );
    }

    /**
     * Resume chain for livestream runs after manual sermon segment confirmation.
     * Starts at ExtractSermon, skipping all upstream segmentation and analysis steps.
     *
     * @return non-empty-list<object>
     */
    public function buildLivestreamPostReviewChainJobs(MediaProcessingLog $log): array
    {
        return [
            new ExtractSermon($log),
            new SubmitToProcessing($log),
            new EnhanceAudio($log),
            new IdentifySpeaker($log),
            new TranscribeAudio($log),
            new ProcessTranscriptWithAI($log),
            new AssessSermonVideoQuality($log),
            new GenerateThumbnail($log),
            new PrepareSectionPublicationCandidates($log),
            new SendCompletionNotification($log),
            new CleanupTemporaryFiles($log),
        ];
    }

    /**
     * Resume chain for auto-trimmed video runs after manual sermon segment confirmation.
     *
     * @return non-empty-list<object>
     */
    public function buildAutoTrimVideoPostReviewChainJobs(MediaProcessingLog $log): array
    {
        return [
            new ExtractSermon($log),
            new EnhanceAudio($log),
            new CreateSermonRecord($log),
            new IdentifySpeaker($log),
            new TranscribeAudio($log),
            new ProcessTranscriptWithAI($log),
            new AssessSermonVideoQuality($log),
            new GenerateThumbnail($log),
            new SendCompletionNotification($log),
            new CleanupTemporaryFiles($log),
        ];
    }

    /**
     * Reclassification chain for existing livestream runs that also refreshes
     * sermon-derived outputs when the original source media is still available.
     *
     * @return non-empty-list<object>
     */
    public function buildSectionReclassificationChainJobs(MediaProcessingLog $log): array
    {
        $mode = ServiceStructureMode::fromConfig();

        if ($mode === ServiceStructureMode::Primary) {
            // Segments already exist on a reclassification run, so
            // AnalyzeSegments is not repeated.
            return [
                new TranscribeFullService($log),
                new DetectServiceStructure($log),
                new ProjectLivestreamServiceStructure($log),
                new MatchSongsFromTranscript($log),
                new ExtractSermon($log),
                new SubmitToProcessing($log),
                new EnhanceAudio($log),
                new IdentifySpeaker($log),
                new TranscribeAudio($log),
                new ProcessTranscriptWithAI($log),
                new AssessSermonVideoQuality($log),
                new GenerateThumbnail($log),
                new PrepareSectionPublicationCandidates($log),
            ];
        }

        $shadowJobs = $mode === ServiceStructureMode::Shadow
            ? [new TranscribeFullService($log), new DetectServiceStructure($log)]
            : [];

        return [
            new ClassifyServiceSections($log, preserveRunStatus: true),
            new TranscribeSpeechSegments($log),
            new ClassifySpeechSections($log),
            new ProjectLivestreamServiceStructure($log),
            new AlignWithOos($log),
            new ResolveReadingReferences($log),
            new MatchSongsFromTranscript($log),
            new ReclassifyIntroOutroSections($log),
            ...$shadowJobs,
            new ExtractSermon($log),
            new SubmitToProcessing($log),
            new EnhanceAudio($log),
            new IdentifySpeaker($log),
            new TranscribeAudio($log),
            new ProcessTranscriptWithAI($log),
            new AssessSermonVideoQuality($log),
            new GenerateThumbnail($log),
            new PrepareSectionPublicationCandidates($log),
        ];
    }
}
