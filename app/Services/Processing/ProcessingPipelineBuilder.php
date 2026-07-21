<?php

declare(strict_types=1);

namespace App\Services\Processing;

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
use App\Jobs\IdentifySpeaker;
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
 * ProcessingPipelineBuilder - Unified job chains for all media processing types
 *
 * The livestream and auto-trim pipelines use the shared
 * full-service transcription and structure-detection seam.
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
            new TranscribeFullService($log),
            new DetectServiceStructure($log),
            new ExtractSermon($log),
            new EnhanceAudio($log),
            new CreateSermonRecord($log),
            new IdentifySpeaker($log),
            new CreateSermonTranscriptFromService($log),
            new ProcessTranscriptWithAI($log),
            new AssessSermonVideoQuality($log),
            new GenerateThumbnail($log),
            new SendCompletionNotification($log),
            new CleanupTemporaryFiles($log),
        ];
    }

    /**
     * Jobs to run in parallel at the start of the livestream pipeline.
     *
     * @return non-empty-list<object>
     */
    public function buildLivestreamParallelJobs(MediaProcessingLog $log): array
    {
        return [new GenerateRmsLog($log)];
    }

    /**
     * Sequential jobs that run after the parallel phase completes.
     *
     * @return non-empty-list<object>
     */
    public function buildLivestreamChainJobs(MediaProcessingLog $log): array
    {
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
            new CreateSermonTranscriptFromService($log),
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
            new CreateSermonTranscriptFromService($log),
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
            new CreateSermonTranscriptFromService($log),
            new ProcessTranscriptWithAI($log),
            new AssessSermonVideoQuality($log),
            new GenerateThumbnail($log),
            new SendCompletionNotification($log),
            new CleanupTemporaryFiles($log),
        ];
    }
}
