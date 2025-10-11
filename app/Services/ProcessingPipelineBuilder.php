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
        ];
    }

    /**
     * Build job pipeline for livestream processing (unified with audio/video)
     */
    public function buildLivestreamPipeline(MediaProcessingLog $log): array
    {
        return [
            new GenerateRmsLog($log),
            new AnalyzeSegments($log),
            new ExtractSermon($log),
            new SubmitToProcessing($log),
            new TranscribeAudio($log),
            new ProcessTranscriptWithAI($log),
            new GenerateThumbnail($log),
            new CleanupTemporaryFiles($log),
        ];
    }
}
