<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\AnalyzeSegments;
use App\Jobs\AnalyzeSermonTranscriptFromLivestream;
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
use App\Jobs\TranscribeSermonAudioFromLivestream;
use App\Jobs\ValidateAudioFile;
use App\Jobs\ValidateVideoFile;
use App\Models\LivestreamProcessingLog;
use App\Models\SermonProcessingLog;

/**
 * ProcessingPipelineBuilder - Creates standardized job chains for different processing types
 *
 * Part of Phase 3 refactoring to unify processing paradigms using async job chains.
 * All processing types will use consistent job chain patterns for better reliability.
 */
class ProcessingPipelineBuilder
{
    /**
     * Build job pipeline for audio processing
     */
    public function buildAudioPipeline(SermonProcessingLog $log): array
    {
        return [
            new ValidateAudioFile($log),
            new CreateSermonRecord($log), // Create sermon record first
            new TranscribeAudio($log),     // Then transcribe (needs sermon_id)
            new ProcessTranscriptWithAI($log),
            new SendCompletionNotification($log),
        ];
    }

    /**
     * Build job pipeline for direct video processing
     *
     * TODO: Create ValidateVideoFile and ExtractAudioFromVideo jobs in Phase 3
     */
    public function buildDirectVideoPipeline(SermonProcessingLog $log): array
    {
        return [
            new ValidateVideoFile($log),
            new ExtractAudioFromVideo($log),
            new TranscribeAudio($log),
            new ProcessTranscriptWithAI($log),
            new CreateSermonRecord($log),
            new GenerateThumbnail($log),
            new SendCompletionNotification($log),
        ];
    }

    /**
     * Build job pipeline for livestream processing (updated with unified processing)
     */
    public function buildLivestreamPipeline(LivestreamProcessingLog $log): array
    {
        return [
            new GenerateRmsLog($log),
            new AnalyzeSegments($log),
            new ExtractSermon($log),
            new SubmitToProcessing($log),
            new TranscribeSermonAudioFromLivestream($log),
            new AnalyzeSermonTranscriptFromLivestream($log),
            new GenerateThumbnail($log),
            new CleanupTemporaryFiles($log),
        ];
    }

    /**
     * Build simplified pipeline for testing or fallback scenarios
     */
    public function buildMinimalPipeline(SermonProcessingLog $log): array
    {
        return [
            new TranscribeAudio($log),
            new CreateSermonRecord($log),
            new SendCompletionNotification($log),
        ];
    }

    /**
     * Get available pipeline types
     */
    public function getAvailablePipelines(): array
    {
        return [
            'audio' => 'Audio processing pipeline',
            'direct_video' => 'Direct video processing pipeline',
            'livestream' => 'Livestream processing pipeline',
            'minimal' => 'Minimal processing pipeline (fallback)',
        ];
    }
}