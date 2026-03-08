<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\AlignWithOos;
use App\Jobs\AnalyzeSegments;
use App\Jobs\ClassifyServiceSections;
use App\Jobs\ClassifySpeechSections;
use App\Jobs\CleanupTemporaryFiles;
use App\Jobs\CreateSermonRecord;
use App\Jobs\ExtractAudioFromVideo;
use App\Jobs\ExtractSermon;
use App\Jobs\GenerateRmsLog;
use App\Jobs\GenerateThumbnail;
use App\Jobs\IdentifySpeaker;
use App\Jobs\PerformVisualAnalysis;
use App\Jobs\PrepareSectionPublicationCandidates;
use App\Jobs\ProcessTranscriptWithAI;
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
            new CreateSermonRecord($log),
            new IdentifySpeaker($log),
            new TranscribeAudio($log),
            new ProcessTranscriptWithAI($log),
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
            new AlignWithOos($log),
            new ExtractSermon($log),
            new SubmitToProcessing($log),
            new IdentifySpeaker($log),
            new TranscribeAudio($log),
            new ProcessTranscriptWithAI($log),
            new GenerateThumbnail($log),
            new PrepareSectionPublicationCandidates($log),
            new SendCompletionNotification($log),
            new CleanupTemporaryFiles($log),
        ];
    }

    /**
     * Lightweight reclassification chain for existing livestream runs.
     *
     * @return non-empty-list<object>
     */
    public function buildSectionReclassificationChainJobs(MediaProcessingLog $log): array
    {
        return [
            new ClassifyServiceSections($log, preserveRunStatus: true),
            new TranscribeSpeechSegments($log),
            new ClassifySpeechSections($log),
            new AlignWithOos($log),
            new PrepareSectionPublicationCandidates($log),
        ];
    }
}
