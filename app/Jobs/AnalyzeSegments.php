<?php

namespace App\Jobs;

use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
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
        private MediaProcessingLog $processingLog
    ) {}

    public function handle(VideoSegmentationService $segmentationService): void
    {
        try {
            // Update status to show segmentation is starting
            $this->processingLog->markAsProcessing('segmentation');

            Log::info('Starting segment analysis', [
                'processing_id' => $this->processingLog->processing_id,
                'rms_log_path' => $this->processingLog->rms_log_path,
            ]);

            if (! $this->processingLog->rms_log_path) {
                throw new \Exception('RMS log path not found in processing log');
            }

            // Check if visual analysis results are available
            $visualClusters = $this->getVisualClusters();

            if ($visualClusters !== null && count($visualClusters) > 0) {
                Log::info('Using visual-guided segmentation', [
                    'processing_id' => $this->processingLog->processing_id,
                    'cluster_count' => count($visualClusters),
                ]);

                $segments = $this->analyzeWithVisualGuidance(
                    $segmentationService,
                    $this->processingLog->rms_log_path,
                    $visualClusters
                );

                $thresholdMetadata = ['method' => 'visual_guided', 'clusters' => count($visualClusters)];
            } else {
                Log::info('Using RMS-only segmentation (no visual data)', [
                    'processing_id' => $this->processingLog->processing_id,
                ]);

                $analysisResult = $segmentationService->analyzeSegments($this->processingLog->rms_log_path);

                // Extract segments and metadata from the analysis result
                $segments = $analysisResult['segments'];
                $thresholdMetadata = $analysisResult['threshold_metadata'];
            }

            if (empty($segments)) {
                throw new \Exception('No segments found in analysis');
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
                    'current_step' => 'segmenting',
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
                    ->onQueue((string) config(
                        'media-processing.queues.livestream',
                        config('media-processing.types.livestream.queue', config('media-processing.queue.name', 'livestream-processing'))
                    ))
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
            $segmentRecord = [
                'media_processing_log_id' => $this->processingLog->id,
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
            ];

            // Add visual analysis fields if present in metadata
            if (isset($segmentData->metadata['visual_confidence'])) {
                $segmentRecord['visual_confidence'] = $segmentData->metadata['visual_confidence'];
            }

            if (isset($segmentData->metadata['visual_sample_count'])) {
                $segmentRecord['visual_sample_count'] = $segmentData->metadata['visual_sample_count'];
            }

            if (isset($segmentData->metadata['calibration_method'])) {
                $segmentRecord['calibration_method'] = $segmentData->metadata['calibration_method'];
            }

            LivestreamSegment::create($segmentRecord);
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

    /**
     * Get visual clusters from processing log
     */
    private function getVisualClusters(): ?array
    {
        // song_clusters is already decoded by Laravel's model cast
        // If it's null or empty array, return null
        $clusters = $this->processingLog->song_clusters;

        return empty($clusters) ? null : $clusters;
    }

    /**
     * Analyze segments using visual guidance
     *
     * @return array<\App\Data\LivestreamSegment>
     */
    private function analyzeWithVisualGuidance(
        VideoSegmentationService $segmentationService,
        string $rmsLogPath,
        array $visualClusters
    ): array {
        $segments = [];
        $segmentOrder = 0;

        // Get total duration from RMS log for sermon identification
        $fullRmsLogPath = \Illuminate\Support\Facades\Storage::disk(config('media-processing.storage.temp_disk'))
            ->path($rmsLogPath);
        $logContent = file_get_contents($fullRmsLogPath);
        $lines = explode("\n", trim($logContent));
        $totalDuration = $this->getTotalDurationFromLog($lines);

        // Process each visual cluster to create song segments
        foreach ($visualClusters as $cluster) {
            // Calibrate threshold for this song
            $calibration = $segmentationService->calibratePerSongThreshold($rmsLogPath, $cluster);

            // Detect precise boundaries
            $segment = $segmentationService->detectBoundariesForCluster(
                $rmsLogPath,
                $cluster,
                $calibration['threshold']
            );

            // Update segment order
            $segment->segmentOrder = $segmentOrder++;

            // Add metadata about calibration
            $segment->metadata = array_merge($segment->metadata ?? [], [
                'song_avg_rms' => $calibration['song_avg_rms'],
                'speech_avg_rms' => $calibration['speech_avg_rms'],
            ]);

            $segments[] = $segment;
        }

        // Generate speech segments from gaps between songs
        $segments = $this->fillGapsWithSpeechSegments($segments, $totalDuration, $segmentOrder);

        return $segments;
    }

    /**
     * Fill gaps between song segments with speech segments
     *
     * @param  array<\App\Data\LivestreamSegment>  $songSegments
     * @return array<\App\Data\LivestreamSegment>
     */
    private function fillGapsWithSpeechSegments(array $songSegments, float $totalDuration, int &$segmentOrder): array
    {
        if (empty($songSegments)) {
            // No songs found, entire video is speech
            return [
                new \App\Data\LivestreamSegment(
                    startTime: 0.0,
                    endTime: $totalDuration,
                    duration: $totalDuration,
                    classification: 'speech',
                    avgRms: -50.0,
                    peakRms: -40.0,
                    segmentOrder: $segmentOrder++
                ),
            ];
        }

        // Sort song segments by start time
        usort($songSegments, fn ($a, $b) => $a->startTime <=> $b->startTime);

        $allSegments = [];
        $previousEnd = 0.0;

        foreach ($songSegments as $songSegment) {
            // Add speech segment before this song if there's a gap
            if ($songSegment->startTime > $previousEnd) {
                $allSegments[] = new \App\Data\LivestreamSegment(
                    startTime: $previousEnd,
                    endTime: $songSegment->startTime,
                    duration: $songSegment->startTime - $previousEnd,
                    classification: 'speech',
                    avgRms: -50.0,
                    peakRms: -40.0,
                    segmentOrder: $segmentOrder++
                );
            }

            // Add the song segment
            $allSegments[] = $songSegment;
            $previousEnd = $songSegment->endTime;
        }

        // Add final speech segment if needed
        if ($previousEnd < $totalDuration) {
            $allSegments[] = new \App\Data\LivestreamSegment(
                startTime: $previousEnd,
                endTime: $totalDuration,
                duration: $totalDuration - $previousEnd,
                classification: 'speech',
                avgRms: -50.0,
                peakRms: -40.0,
                segmentOrder: $segmentOrder++
            );
        }

        return $allSegments;
    }

    /**
     * Get total duration from RMS log lines
     */
    private function getTotalDurationFromLog(array $lines): float
    {
        $maxTime = 0.0;

        foreach ($lines as $line) {
            if (preg_match('/pts_time:(\d+(?:\.\d+)?)/', $line, $matches)) {
                $maxTime = max($maxTime, (float) $matches[1]);
            }
        }

        // Fallback estimate if no pts_time found
        if ($maxTime === 0.0) {
            $maxTime = count($lines) / 43.0;
        }

        return $maxTime;
    }
}
