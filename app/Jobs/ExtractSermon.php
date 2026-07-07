<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Data\LivestreamSegment;
use App\Enums\LivestreamSegmentClassification;
use App\Mail\ManualReviewRequired;
use App\Models\MediaProcessingLog;
use App\Services\Media\Video\VideoExtractionService;
use App\Services\Media\Video\VideoStorageService;
use App\Services\Processing\StorageAdapterHelper;
use App\Services\Sermon\SermonCandidateConfidenceService;
use App\Services\Sermon\SermonExtractionPlanResolver;
use App\Support\ChurchServiceProcessingTimeline;
use App\Traits\DetectsStorageType;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class ExtractSermon extends ProcessingJob implements ShouldQueue
{
    use DetectsStorageType, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 3600;

    public function __construct(
        private MediaProcessingLog $processingLog
    ) {}

    public function handle(
        VideoExtractionService $videoExtractor,
        VideoStorageService $storageService,
        StorageAdapterHelper $storageHelper,
        SermonExtractionPlanResolver $planResolver,
        SermonCandidateConfidenceService $sermonConfidenceService
    ): void {
        try {
            $processingLog = $this->processingLog->fresh();
            if (! $processingLog instanceof MediaProcessingLog) {
                throw new \Exception('Processing log not found in database');
            }

            $this->processingLog = $processingLog;
            $this->startProcessingJob($this->processingLog, $this->job ?? null, $this->attempts());

            if ($this->processingLog->isCancelled()) {
                $this->logStepSkipped(ChurchServiceProcessingTimeline::EXTRACT_SERMON, 'Processing cancelled');

                Log::info('ExtractSermon job skipped: processing cancelled', [
                    'processing_id' => $this->processingLog->processing_id,
                ]);

                return;
            }

            $this->logStepStart(ChurchServiceProcessingTimeline::EXTRACT_SERMON);

            // Update status to show sermon extraction is starting
            $this->markProcessingRunAsProcessing($this->processingLog, 'extraction');

            $extractionPlan = $planResolver->resolve($this->processingLog);
            $extractionPlan = $this->guardAutoExtractionPolicy(
                $extractionPlan,
                $sermonConfidenceService
            );

            if ($extractionPlan === null) {
                $this->logStepSkipped(ChurchServiceProcessingTimeline::EXTRACT_SERMON, 'Awaiting manual sermon review');

                return;
            }

            $firstSegment = $extractionPlan['segments'][0] ?? null;
            if (! is_array($firstSegment)) {
                throw new \Exception('Invalid sermon extraction plan: no extraction segments were resolved');
            }

            $this->recordExtractionPlanAudit($extractionPlan);

            Log::info('Starting sermon extraction', [
                'processing_id' => $this->processingLog->processing_id,
                'sermon_start_time' => $firstSegment['start_time'],
                'sermon_end_time' => $firstSegment['end_time'],
                'bounds_source' => $extractionPlan['source'],
                'mode' => $extractionPlan['mode'],
                'segment_count' => count($extractionPlan['segments']),
            ]);

            $tempDisk = (string) config('media-processing.storage.temp_disk', 'local');
            $isS3TempDisk = $this->isS3Disk($tempDisk);
            $sourceFilePath = $this->requireSourceFilePath();

            if ($isS3TempDisk) {
                if (! Storage::disk($tempDisk)->exists($sourceFilePath)) {
                    throw new \Exception('Original video file not found on S3: '.$sourceFilePath);
                }

                $videoPath = $storageHelper->downloadToTemp(
                    $sourceFilePath,
                    $tempDisk,
                    'local',
                    'temp/extraction'
                );
            } else {
                $videoPath = Storage::disk($tempDisk)->path($sourceFilePath);

                // Wait for file to be available (handles async upload/storage delays)
                $maxAttempts = 5;
                $attempt = 0;
                while (! file_exists($videoPath) && $attempt < $maxAttempts) {
                    $attempt++;
                    Log::warning('Video file not yet available, waiting...', [
                        'processing_id' => $this->processingLog->processing_id,
                        'attempt' => $attempt,
                        'expected_path' => $videoPath,
                    ]);
                    sleep(2);
                }

                if (! file_exists($videoPath)) {
                    throw new \Exception('Original video file not found after waiting: '.$videoPath);
                }
            }

            try {
                if ($extractionPlan['mode'] === 'concat_spans') {
                    $sermonVideoPath = $videoExtractor->extractConcatenatedSegmentAsFile(
                        $videoPath,
                        $extractionPlan['segments'],
                        $this->processingLog->processing_id.'_sermon.mp4'
                    );

                    $sermonVideoAbsolutePath = Storage::disk($tempDisk)->path($sermonVideoPath);
                    $concatDuration = $this->totalPlannedDuration($extractionPlan['segments']);
                    $audioSegment = $this->createSermonSegment(0.0, $concatDuration);

                    $audioExtractionResult = $videoExtractor->extractOptimizedAudio(
                        $sermonVideoAbsolutePath,
                        $audioSegment,
                        $this->processingLog->processing_id.'_sermon.mp3'
                    );
                } else {
                    $sermonSegment = $this->createSermonSegment(
                        (float) $firstSegment['start_time'],
                        (float) $firstSegment['end_time']
                    );

                    $sermonVideoPath = $videoExtractor->extractSegmentAsFile(
                        $videoPath,
                        $sermonSegment,
                        $this->processingLog->processing_id.'_sermon.mp4'
                    );

                    $audioExtractionResult = $videoExtractor->extractOptimizedAudio(
                        $videoPath,
                        $sermonSegment,
                        $this->processingLog->processing_id.'_sermon.mp3'
                    );
                }

                $sermonAudioPath = $audioExtractionResult['audio_path'];
            } finally {
                // Clean up temporary S3 download file if we created one
                if ($isS3TempDisk) {
                    $storageHelper->cleanupTempFile($videoPath);
                }
            }

            // DEFENSIVE: Verify audio file actually exists before storing path in database
            $audioFullPath = $audioExtractionResult['full_path'];
            $audioFileExists = $this->verifyAudioFileExists($storageService, $sermonAudioPath, $audioFullPath);

            if (! $audioFileExists) {
                throw new \Exception("Audio extraction claimed success but file does not exist: {$audioFullPath}");
            }

            Log::info('Audio file existence verified before database update', [
                'processing_id' => $this->processingLog->processing_id,
                'audio_path' => $sermonAudioPath,
                'full_path' => $audioFullPath,
                'file_exists' => $audioFileExists,
                'verification_method' => $this->isS3Path($audioFullPath) ? 's3_storage' : 'local_filesystem',
            ]);

            $this->processingLog->update([
                'video_file_path' => $sermonVideoPath,
                'audio_file_path' => $sermonAudioPath,
                'current_step' => 'extraction_complete',
                'processing_metadata' => array_merge(
                    $this->processingLog->processing_metadata?->toArray() ?? [],
                    [
                        'extracted_segment_path' => $sermonVideoPath,
                        'extracted_audio_path' => $sermonAudioPath,
                        'trim' => [
                            'original_duration' => $this->processingLog->duration,
                            'final_duration' => $this->totalPlannedDuration($extractionPlan['segments']),
                            'trim_start' => (float) $extractionPlan['segments'][0]['start_time'],
                            'trim_end' => (float) $extractionPlan['segments'][count($extractionPlan['segments']) - 1]['end_time'],
                            'segments' => $extractionPlan['segments'],
                            'source' => $extractionPlan['source'],
                            'strategy' => $extractionPlan['metadata']['strategy'] ?? null,
                            'mode' => $extractionPlan['mode'],
                        ],
                        'audio_compression' => [
                            'original_size_mb' => round($audioExtractionResult['original_size'] / 1024 / 1024, 1),
                            'final_size_mb' => round($audioExtractionResult['final_size'] / 1024 / 1024, 1),
                            'compression_applied' => $audioExtractionResult['compression_applied'],
                            'compression_ratio' => round($audioExtractionResult['compression_ratio'], 2),
                            'valid_for_transcription' => $audioExtractionResult['valid_for_transcription'],
                        ],
                    ]
                ),
            ]);

            if (! $audioExtractionResult['valid_for_transcription']) {
                Log::warning('Audio file still too large after compression', [
                    'processing_id' => $this->processingLog->processing_id,
                    'final_size_mb' => round($audioExtractionResult['final_size'] / 1024 / 1024, 1),
                    'compression_ratio' => round($audioExtractionResult['compression_ratio'], 2),
                ]);
            }

            Log::info('Sermon extraction completed', [
                'processing_id' => $this->processingLog->processing_id,
                'video_path' => $sermonVideoPath,
                'audio_path' => $sermonAudioPath,
                'audio_full_path' => $audioExtractionResult['full_path'],
                'compression_applied' => $audioExtractionResult['compression_applied'],
                'original_audio_size_mb' => round($audioExtractionResult['original_size'] / 1024 / 1024, 1),
                'final_audio_size_mb' => round($audioExtractionResult['final_size'] / 1024 / 1024, 1),
                'compression_ratio' => $audioExtractionResult['compression_ratio'],
                'valid_for_transcription' => $audioExtractionResult['valid_for_transcription'],
                'file_exists_check' => $audioFileExists,
            ]);

            $this->logStepComplete(
                ChurchServiceProcessingTimeline::EXTRACT_SERMON,
                sprintf('Extracted sermon media using %s plan', $extractionPlan['mode'])
            );

            // Job chain will automatically proceed to next job

        } catch (\Exception $e) {
            $this->initializeStepLogging($this->processingLog->processing_id);
            $this->logStepFailed(ChurchServiceProcessingTimeline::EXTRACT_SERMON, $e->getMessage());

            Log::error('Sermon extraction failed', [
                'processing_id' => $this->processingLog->processing_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->markProcessingRunAsFailed($this->processingLog, 'Sermon extraction failed: '.$e->getMessage());

            // Cleanup will be handled by the chain failure handler

            throw $e;
        }
    }

    private function createSermonSegment(float $startTime, float $endTime): LivestreamSegment
    {
        return new LivestreamSegment(
            startTime: $startTime,
            endTime: $endTime,
            duration: $endTime - $startTime,
            classification: LivestreamSegmentClassification::Speech->value,
            avgRms: 0.0, // Not needed for extraction
            peakRms: 0.0, // Not needed for extraction
            isSermonCandidate: true,
            segmentOrder: 0
        );
    }

    protected function onJobFailure(\Throwable $exception): void
    {
        $this->initializeStepLogging($this->processingLog->processing_id);
        $this->logStepFailed(
            ChurchServiceProcessingTimeline::EXTRACT_SERMON,
            'Sermon extraction failed after '.$this->tries.' attempts: '.$exception->getMessage()
        );

        $this->markProcessingRunAsFailed(
            $this->processingLog,
            'Sermon extraction failed after '.$this->tries.' attempts: '.$exception->getMessage()
        );
    }

    /**
     * Verify audio file exists using appropriate method for storage type
     */
    private function verifyAudioFileExists(VideoStorageService $storageService, string $audioPath, string $fullPath): bool
    {
        // For S3 URLs, use Storage disk to verify existence
        if ($this->isS3Path($fullPath)) {
            $diskName = config('media-processing.storage.sermon_disk', 'public');

            return Storage::disk($diskName)->exists($audioPath);
        }

        // For local paths, use file_exists
        return file_exists($fullPath);
    }

    private function requireSourceFilePath(): string
    {
        $sourceFilePath = $this->processingLog->source_file_path;
        if (! is_string($sourceFilePath) || $sourceFilePath === '') {
            throw new \Exception('No source video path found in processing log');
        }

        return $sourceFilePath;
    }

    /**
     * @param  array<int, array{start_time: float, end_time: float}>  $segments
     */
    private function totalPlannedDuration(array $segments): float
    {
        $duration = 0.0;

        foreach ($segments as $segment) {
            $duration += max(0.0, (float) $segment['end_time'] - (float) $segment['start_time']);
        }

        if ($duration <= 0.0) {
            throw new \Exception('Invalid extraction plan duration');
        }

        return $duration;
    }

    /**
     * @param  array{
     *     mode: 'single_span'|'concat_spans'|'baseline',
     *     source: 'service_sections'|'processing_log'|'manual_review',
     *     segments: array<int, array{start_time: float, end_time: float}>,
     *     metadata: array<string, mixed>
     * }  $extractionPlan
     * @return array{
     *     mode: 'single_span'|'concat_spans'|'baseline',
     *     source: 'service_sections'|'processing_log'|'manual_review',
     *     segments: array<int, array{start_time: float, end_time: float}>,
     *     metadata: array<string, mixed>
     * }|null
     */
    private function guardAutoExtractionPolicy(
        array $extractionPlan,
        SermonCandidateConfidenceService $sermonConfidenceService
    ): ?array {
        if ($extractionPlan['source'] !== 'processing_log') {
            return $extractionPlan;
        }

        $evaluation = $sermonConfidenceService->evaluateForProcessingLog($this->processingLog);
        $speechSegments = $evaluation['speech_segments'];

        if ($speechSegments === []) {
            return $extractionPlan;
        }

        if (! $evaluation['is_clear']) {
            $reasonCode = $evaluation['reason'];
            $reasonMessage = $this->manualReviewReason($reasonCode);
            $this->markProcessingRunForManualReview($this->processingLog, $reasonCode, $reasonMessage, $speechSegments);
            $this->processingLog->refresh();
            $this->notifyManualReviewRequired($reasonMessage, $speechSegments);

            // Stop the remaining chained jobs; extraction is intentionally deferred.
            $this->chained = [];

            Log::warning('Sermon extraction halted for manual review', [
                'processing_id' => $this->processingLog->processing_id,
                'reason' => $evaluation['reason'],
                'speech_segment_count' => count($speechSegments),
            ]);

            return null;
        }

        $candidate = $evaluation['candidate'];

        if (! $candidate instanceof \App\Models\LivestreamSegment) {
            return $extractionPlan;
        }

        return [
            'mode' => 'single_span',
            'source' => 'processing_log',
            'segments' => [[
                'start_time' => (float) $candidate->start_time,
                'end_time' => (float) $candidate->end_time,
            ]],
            'metadata' => array_merge($extractionPlan['metadata'], [
                'strategy' => 'dominant_speech_segment',
                'sermon_segment_id' => $candidate->id,
                'next_longest_duration' => $evaluation['next_longest_duration'],
            ]),
        ];
    }

    private function manualReviewReason(string $reason): string
    {
        return match ($reason) {
            'no_qualifying_speech_block' => 'No speech block met the 20-minute sermon threshold.',
            'multiple_qualifying_speech_blocks' => 'Multiple speech blocks met the 20-minute sermon threshold.',
            'ratio_below_threshold' => 'The longest speech block was not at least 1.5x longer than the next-longest speech block.',
            'candidate_exceeds_maximum_duration' => 'The longest speech block exceeded the maximum plausible sermon duration, indicating under-segmentation.',
            default => 'Sermon auto-selection confidence was insufficient.',
        };
    }

    /**
     * @param  array<int, array{segment_id: int, start_time: float, end_time: float, duration: float}>  $speechSegments
     */
    private function notifyManualReviewRequired(string $reason, array $speechSegments): void
    {
        try {
            Mail::to(config('media-processing.email.admin_email'))
                ->queue(new ManualReviewRequired($this->processingLog->processing_id, $reason, $speechSegments));
        } catch (\Exception $exception) {
            Log::warning('Failed to queue manual review required email, continuing', [
                'processing_id' => $this->processingLog->processing_id,
                'reason' => $reason,
                'email_error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Keep a durable record of which bounds the clip was actually cut from.
     * The "Starting sermon extraction" log line carries the same facts but
     * does not survive log rotation; this metadata is what lets an operator
     * later tell an LLM-structure cut from a silent RMS-baseline fallback.
     *
     * The run-level sermon bounds are aligned to the same plan: the guard can
     * replace a baseline plan with the dominant RMS candidate after any
     * earlier write-back, and SubmitToProcessing/SermonMetadataIntegration
     * persist these fields as the Sermon's segment times — they must describe
     * the media actually cut, whichever source won.
     *
     * A concat plan joins several spans across a gap, so its media duration is
     * the sum of the spans, not last-end minus first-start. Anchoring the end
     * at start + summed duration keeps sermon_end_time - sermon_start_time — the
     * value every downstream consumer (SermonMetadataIntegration::getSegmentDuration,
     * StandardProcessingResponse) reads as the segment duration — equal to the
     * clip that was actually cut. For a single-span plan the sum equals
     * last-end minus first-start, so the end is unchanged there. The precise
     * discontinuous spans remain available in the recorded segment list.
     *
     * @param  array{mode: string, source: string, segments: array<int, array{start_time: float, end_time: float}>, metadata: array<string, mixed>}  $extractionPlan
     */
    private function recordExtractionPlanAudit(array $extractionPlan): void
    {
        $metadata = $this->processingLog->processing_metadata?->toArray() ?? [];
        $metadata['sermon_extraction_plan'] = [
            'source' => $extractionPlan['source'],
            'mode' => $extractionPlan['mode'],
            'strategy' => $extractionPlan['metadata']['strategy'] ?? null,
            'reason' => $extractionPlan['metadata']['reason'] ?? null,
            'segments' => $extractionPlan['segments'],
            'resolved_at' => now()->toIso8601String(),
        ];

        $segments = array_values($extractionPlan['segments']);
        $startTime = (float) $segments[0]['start_time'];

        $cutDuration = 0.0;
        foreach ($segments as $segment) {
            $cutDuration += max(0.0, (float) $segment['end_time'] - (float) $segment['start_time']);
        }

        $this->processingLog->forceFill([
            'sermon_start_time' => $startTime,
            'sermon_end_time' => $startTime + $cutDuration,
            'processing_metadata' => $metadata,
        ])->save();
    }
}
