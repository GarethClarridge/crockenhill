<?php

namespace App\Jobs;

use App\Data\LivestreamSegment;
use App\Enums\LivestreamSegmentClassification;
use App\Models\LivestreamSegment as LivestreamSegmentModel;
use App\Models\MediaProcessingLog;
use App\Services\VideoSegmentationService;
use App\Traits\ChecksCancellation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AnalyzeSegments implements ShouldQueue
{
    use ChecksCancellation, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 1800;

    public function __construct(
        private MediaProcessingLog $processingLog
    ) {}

    public function handle(VideoSegmentationService $segmentationService): void
    {
        if ($this->abortIfCancelled('AnalyzeSegments')) {
            return;
        }

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
                /** @var array<int, LivestreamSegment> $segments */
                $segments = $analysisResult['segments'];
                /** @var array<string, mixed> $thresholdMetadata */
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
                    ->onQueue((string) config('media-processing.queues.livestream', 'livestream-processing'))
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

    /**
     * @param  array<string, mixed>  $thresholdMetadata
     */
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

    /**
     * @param  array<int, LivestreamSegment>  $segments
     */
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

            LivestreamSegmentModel::create($segmentRecord);
        }

        Log::info('Segments stored in database', [
            'processing_id' => $this->processingLog->processing_id,
            'segment_count' => count($segments),
        ]);
    }

    /**
     * @param  array<int, LivestreamSegment>  $segments
     */
    private function findSermonCandidate(array $segments): ?LivestreamSegment
    {
        $speechSegments = array_filter($segments, fn ($s) => $s->isSpeech());

        if (empty($speechSegments)) {
            return null;
        }

        usort($speechSegments, fn ($a, $b) => $b->duration <=> $a->duration);
        $longestSpeechSegment = $speechSegments[0];

        $minSermonDuration = (float) config('media-processing.segmentation.min_sermon_duration', 300.0);

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
     *
     * @return array<int, array{
     *     start_estimate: float,
     *     end_estimate: float,
     *     samples: array<int, float>,
     *     confidence: float,
     *     sample_count?: int,
     *     refined_visual_start?: float,
     *     refined_visual_end?: float,
     *     dense_sample_count?: int
     * }>|null
     */
    private function getVisualClusters(): ?array
    {
        $rawClusters = $this->processingLog->song_clusters;
        if (! is_array($rawClusters) || $rawClusters === []) {
            return null;
        }

        $clusters = [];
        foreach ($rawClusters as $rawCluster) {
            $startEstimate = $rawCluster['start_estimate'] ?? null;
            $endEstimate = $rawCluster['end_estimate'] ?? null;
            $samples = $rawCluster['samples'] ?? null;
            if (! is_numeric($startEstimate) || ! is_numeric($endEstimate) || ! is_array($samples)) {
                continue;
            }

            /** @var array{
             *     start_estimate: float,
             *     end_estimate: float,
             *     samples: array<int, float>,
             *     confidence: float,
             *     sample_count?: int,
             *     refined_visual_start?: float,
             *     refined_visual_end?: float,
             *     dense_sample_count?: int
             * } $normalizedCluster */
            $normalizedCluster = [
                'start_estimate' => (float) $startEstimate,
                'end_estimate' => (float) $endEstimate,
                'samples' => array_values(array_map('floatval', $samples)),
                'confidence' => is_numeric($rawCluster['confidence'] ?? null) ? (float) $rawCluster['confidence'] : 0.0,
            ];

            if (is_numeric($rawCluster['sample_count'] ?? null)) {
                $normalizedCluster['sample_count'] = (int) $rawCluster['sample_count'];
            }
            if (is_numeric($rawCluster['refined_visual_start'] ?? null)) {
                $normalizedCluster['refined_visual_start'] = (float) $rawCluster['refined_visual_start'];
            }
            if (is_numeric($rawCluster['refined_visual_end'] ?? null)) {
                $normalizedCluster['refined_visual_end'] = (float) $rawCluster['refined_visual_end'];
            }
            if (is_numeric($rawCluster['dense_sample_count'] ?? null)) {
                $normalizedCluster['dense_sample_count'] = (int) $rawCluster['dense_sample_count'];
            }

            $clusters[] = $normalizedCluster;
        }

        return $clusters === [] ? null : $clusters;
    }

    /**
     * Analyze segments using visual guidance
     *
     * @param  array<int, array{
     *     start_estimate: float,
     *     end_estimate: float,
     *     samples: array<int, float>,
     *     confidence: float,
     *     sample_count?: int,
     *     refined_visual_start?: float,
     *     refined_visual_end?: float,
     *     dense_sample_count?: int
     * }>  $visualClusters
     * @return array<int, LivestreamSegment>
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
        if ($logContent === false) {
            throw new \Exception('Unable to read RMS log for visual-guided analysis');
        }
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
     * @param  array<LivestreamSegment>  $songSegments
     * @return array<LivestreamSegment>
     */
    private function fillGapsWithSpeechSegments(array $songSegments, float $totalDuration, int &$segmentOrder): array
    {
        if (empty($songSegments)) {
            // No songs found, entire video is speech
            return [
                new LivestreamSegment(
                    startTime: 0.0,
                    endTime: $totalDuration,
                    duration: $totalDuration,
                    classification: LivestreamSegmentClassification::Speech->value,
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
                $allSegments[] = new LivestreamSegment(
                    startTime: $previousEnd,
                    endTime: $songSegment->startTime,
                    duration: $songSegment->startTime - $previousEnd,
                    classification: LivestreamSegmentClassification::Speech->value,
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
            $allSegments[] = new LivestreamSegment(
                startTime: $previousEnd,
                endTime: $totalDuration,
                duration: $totalDuration - $previousEnd,
                classification: LivestreamSegmentClassification::Speech->value,
                avgRms: -50.0,
                peakRms: -40.0,
                segmentOrder: $segmentOrder++
            );
        }

        return $allSegments;
    }

    /**
     * Get total duration from RMS log lines
     *
     * @param  array<int, string>  $lines
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
