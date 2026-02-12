<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\AnalyzeSegments;
use App\Jobs\CleanupTemporaryFiles;
use App\Jobs\CreateSermonRecord;
use App\Jobs\ExtractAudioFromVideo;
use App\Jobs\ExtractSermon;
use App\Jobs\GenerateRmsLog;
use App\Jobs\GenerateThumbnail;
use App\Jobs\PerformVisualAnalysis;
use App\Jobs\ProcessTranscriptWithAI;
use App\Jobs\SendCompletionNotification;
use App\Jobs\SubmitToProcessing;
use App\Jobs\TranscribeAudio;
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
     */
    public function buildAudioPipeline(MediaProcessingLog $log): array
    {
        return [
            new ValidateAudioFile($log),
            new CreateSermonRecord($log),
            new TranscribeAudio($log),
            new ProcessTranscriptWithAI($log),
            new SendCompletionNotification($log),
            new CleanupTemporaryFiles($log),
        ];
    }

    /**
     * Build job pipeline for direct video processing
     */
    public function buildDirectVideoPipeline(MediaProcessingLog $log): array
    {
        return [
            new ValidateVideoFile($log),
            new ExtractAudioFromVideo($log),
            new CreateSermonRecord($log),
            new TranscribeAudio($log),
            new ProcessTranscriptWithAI($log),
            new GenerateThumbnail($log),
            new SendCompletionNotification($log),
            new CleanupTemporaryFiles($log),
        ];
    }

    /**
     * Build job pipeline for livestream processing (unified with audio/video)
     */
    public function buildLivestreamPipeline(MediaProcessingLog $log): array
    {
        $jobs = [];

        // Visual analysis (if enabled) - runs before RMS analysis
        if (config('media-processing.visual_analysis.enabled', true)) {
            $jobs[] = new PerformVisualAnalysis($log);
        }

        // RMS generation (always needed for segmentation)
        $jobs[] = new GenerateRmsLog($log);

        // Segment analysis (uses visual results if available, falls back to RMS-only)
        $jobs[] = new AnalyzeSegments($log);

        // Continue with sermon extraction and processing
        $jobs[] = new ExtractSermon($log);
        $jobs[] = new SubmitToProcessing($log);
        $jobs[] = new TranscribeAudio($log);
        $jobs[] = new ProcessTranscriptWithAI($log);
        $jobs[] = new GenerateThumbnail($log);
        $jobs[] = new CleanupTemporaryFiles($log);

        return $jobs;
    }
}
