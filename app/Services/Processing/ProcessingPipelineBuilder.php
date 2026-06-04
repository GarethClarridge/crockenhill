<?php

declare(strict_types=1);

namespace App\Services\Processing;

use App\Jobs\AlignWithOos;
use App\Jobs\AnalyzeSegments;
use App\Jobs\AssessSermonVideoQuality;
use App\Jobs\ClassifyServiceSections;
use App\Jobs\ClassifySpeechSections;
use App\Jobs\CleanupTemporaryFiles;
use App\Jobs\CreateSermonRecord;
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
use App\Jobs\SendCompletionNotification;
use App\Jobs\SubmitToProcessing;
use App\Jobs\TranscribeAudio;
use App\Jobs\TranscribeSpeechSegments;
use App\Jobs\ValidateAudioFile;
use App\Jobs\ValidateVideoFile;
use App\Models\MediaProcessingLog;

/**
 * ProcessingPipelineBuilder - Unified job chains for all media processing types
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
        return [
            new AnalyzeSegments($log),
            new ClassifyServiceSections($log),
            new TranscribeSpeechSegments($log),
            new ClassifySpeechSections($log),
            new ProjectLivestreamServiceStructure($log),
            new AlignWithOos($log),
            new MatchSongsFromTranscript($log),
            new ReclassifyIntroOutroSections($log),
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
        return [
            new ClassifyServiceSections($log, preserveRunStatus: true),
            new TranscribeSpeechSegments($log),
            new ClassifySpeechSections($log),
            new ProjectLivestreamServiceStructure($log),
            new AlignWithOos($log),
            new MatchSongsFromTranscript($log),
            new ReclassifyIntroOutroSections($log),
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
