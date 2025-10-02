<?php

namespace App\Jobs;

use App\Models\LivestreamProcessingLog;
use App\Models\LivestreamSegment;
use App\Services\VideoSegmentationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AnalyzeSegments implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 1800;

    public function __construct(
        private LivestreamProcessingLog $processingLog
    ) {}

    public function handle(VideoSegmentationService $segmentationService): void
    {
        try {
            // Update status to show segmentation is starting
            $this->processingLog->update(['status' => 'segmentation']);

            Log::info('Starting segment analysis', [
                'processing_id' => $this->processingLog->processing_id,
                'rms_log_path' => $this->processingLog->rms_log_path,
            ]);

            if (! $this->processingLog->rms_log_path) {
                throw new \Exception('RMS log path not found in processing log');
            }

            $analysisResult = $segmentationService->analyzeSegments($this->processingLog->rms_log_path);

            // Extract segments and metadata from the analysis result
            $segments = $analysisResult['segments'];
            $thresholdMetadata = $analysisResult['threshold_metadata'];

            if (empty($segments)) {
                throw new \Exception('No segments found in RMS log analysis');
            }

            // Store threshold metadata if available
            if ($thresholdMetadata) {
                $this->storeThresholdMetadata($thresholdMetadata);
            }

            $this->storeSegments($segments);

            $sermonCandidate = $this->findSermonCandidate($segments);

            if ($sermonCandidate) {
                $this->processingLog->update([
                    'sermon_start_time' => $sermonCandidate->startTime,
                    'sermon_end_time' => $sermonCandidate->endTime,
                    'status' => 'segmenting',
                ]);

                Log::info('Sermon candidate identified', [
                    'processing_id' => $this->processingLog->processing_id,
                    'start_time' => $sermonCandidate->startTime,
                    'end_time' => $sermonCandidate->endTime,
                    'duration' => $sermonCandidate->duration,
                ]);

                // Job chain will automatically proceed to next job
            } else {
                Log::warning('No sermon candidate found', [
                    'processing_id' => $this->processingLog->processing_id,
                    'total_segments' => count($segments),
                    'speech_segments' => count(array_filter($segments, fn ($s) => $s->isSpeech())),
                ]);

                $this->processingLog->markAsFailed(
                    'No sermon candidate found. Longest speech segment does not meet minimum duration requirements.'
                );

                CleanupTemporaryFiles::dispatch($this->processingLog)
                    ->onQueue(config('media-processing.queue.name'))
                    ->delay(now()->addMinutes(5));
            }

        } catch (\Exception $e) {
            Log::error('Segment analysis failed', [
                'processing_id' => $this->processingLog->processing_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->processingLog->markAsFailed('Segment analysis failed: '.$e->getMessage());

            // Cleanup will be handled by the chain failure handler

            throw $e;
        }
    }

    private function storeThresholdMetadata(array $thresholdMetadata): void
    {
        $updateData = [
            'threshold_method' => $thresholdMetadata['method'] ?? 'unknown',
        ];

        // Add adaptive threshold value if present
        if (isset($thresholdMetadata['threshold'])) {
            $updateData['adaptive_threshold'] = $thresholdMetadata['threshold'];
        }

        // Add RMS statistics if present
        if (isset($thresholdMetadata['rms_stats'])) {
            $updateData['rms_stats'] = json_encode($thresholdMetadata['rms_stats']);
        }

        $this->processingLog->update($updateData);

        Log::info('Threshold metadata stored', [
            'processing_id' => $this->processingLog->processing_id,
            'threshold_method' => $thresholdMetadata['method'] ?? 'unknown',
            'threshold_value' => $thresholdMetadata['threshold'] ?? null,
        ]);
    }

    private function storeSegments(array $segments): void
    {
        foreach ($segments as $segmentData) {
            LivestreamSegment::create([
                'processing_id' => $this->processingLog->processing_id,
                'processing_log_id' => $this->processingLog->id,
                'segment_index' => $segmentData->segmentOrder,
                'start_time' => $segmentData->startTime,
                'end_time' => $segmentData->endTime,
                'duration' => $segmentData->duration,
                'classification' => $segmentData->classification,
                'avg_rms' => $segmentData->avgRms,
                'peak_rms' => $segmentData->peakRms,
                'is_sermon_candidate' => $segmentData->isSermonCandidate,
                'segment_order' => $segmentData->segmentOrder,
                'metadata' => $segmentData->metadata,
            ]);
        }

        Log::info('Segments stored in database', [
            'processing_id' => $this->processingLog->processing_id,
            'segment_count' => count($segments),
        ]);
    }

    private function findSermonCandidate(array $segments): ?\App\Data\LivestreamSegment
    {
        $speechSegments = array_filter($segments, fn ($s) => $s->isSpeech());

        if (empty($speechSegments)) {
            return null;
        }

        usort($speechSegments, fn ($a, $b) => $b->duration <=> $a->duration);
        $longestSpeechSegment = $speechSegments[0];

        $minSermonDuration = config('media-processing.segmentation.min_sermon_duration');

        if ($longestSpeechSegment->duration >= $minSermonDuration) {
            return $longestSpeechSegment;
        }

        return null;
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('AnalyzeSegments job failed permanently', [
            'processing_id' => $this->processingLog->processing_id,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);

        $this->processingLog->markAsFailed(
            'Segment analysis failed after '.$this->tries.' attempts: '.$exception->getMessage()
        );

        // Cleanup will be handled by the chain failure handler
    }

    public function retryUntil(): \DateTime
    {
        return now()->addHours(1);
    }
}
