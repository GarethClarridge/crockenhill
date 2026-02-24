<?php

namespace App\Jobs;

use App\Models\MediaProcessingLog;
use App\Services\SongClusteringService;
use App\Services\VisualAnalysisService;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PerformVisualAnalysis implements ShouldQueue
{
    use Batchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 3600; // 1 hour for large videos

    public function __construct(
        private MediaProcessingLog $processingLog
    ) {}

    public function handle(
        VisualAnalysisService $visualService,
        SongClusteringService $clusteringService
    ): void {
        try {
            if ($this->processingLog->fresh()->isCancelled()) {
                Log::info('PerformVisualAnalysis job skipped: processing cancelled', [
                    'processing_id' => $this->processingLog->processing_id,
                ]);

                $this->batch()?->cancel();

                return;
            }

            // Check if visual analysis is enabled
            if (! config('media-processing.visual_analysis.enabled', true)) {
                Log::info('Visual analysis is disabled, skipping', [
                    'processing_id' => $this->processingLog->processing_id,
                ]);

                return;
            }

            // Idempotency check: skip if visual analysis already completed
            if ($this->processingLog->visual_sample_count !== null) {
                Log::info('Visual analysis already completed, skipping re-run', [
                    'processing_id' => $this->processingLog->processing_id,
                    'sample_count' => $this->processingLog->visual_sample_count,
                    'cluster_count' => count($this->processingLog->song_clusters ?? []),
                ]);

                return;
            }

            Log::info('Starting visual analysis', [
                'processing_id' => $this->processingLog->processing_id,
                'log_id' => $this->processingLog->id,
            ]);

            // Update status to show visual analysis is starting
            $this->processingLog->markAsProcessing('visual_analysis');

            $videoPath = Storage::disk(config('media-processing.storage.temp_disk'))
                ->path($this->processingLog->source_file_path);

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
                sleep(2); // Wait 2 seconds before retrying
            }

            if (! file_exists($videoPath)) {
                throw new \Exception('Video file not found after waiting: '.$videoPath);
            }

            $startTime = microtime(true);
            $videoDuration = $this->processingLog->duration;

            // Perform visual analysis with progress reporting (throttled to one DB write per 30s)
            $lastProgressUpdate = 0.0;
            $progressCallback = function (float $currentTime) use ($videoDuration, &$lastProgressUpdate): void {
                if ($currentTime - $lastProgressUpdate < 30.0) {
                    return;
                }
                $lastProgressUpdate = $currentTime;

                $percentage = $videoDuration > 0
                    ? min(99, (int) round(($currentTime / $videoDuration) * 100))
                    : 0;

                Log::info('Visual analysis progress', [
                    'processing_id' => $this->processingLog->processing_id,
                    'position' => gmdate('H:i:s', (int) $currentTime),
                    'duration' => gmdate('H:i:s', (int) $videoDuration),
                    'percentage' => $percentage.'%',
                ]);

                $this->processingLog->update([
                    'processing_metadata' => array_merge(
                        $this->processingLog->processing_metadata ?? [],
                        ['visual_analysis_progress' => $percentage]
                    ),
                ]);
            };

            $visualSamples = $visualService->analyzeVideo($videoPath, null, $progressCallback);

            if (empty($visualSamples)) {
                Log::warning('No visual samples extracted, will fallback to RMS-only', [
                    'processing_id' => $this->processingLog->processing_id,
                ]);

                $this->processingLog->update([
                    'visual_sample_count' => 0,
                    'visual_processing_time' => microtime(true) - $startTime,
                ]);

                return;
            }

            // Cluster song periods
            $songClusters = $clusteringService->clusterSongPeriods($visualSamples);

            // Check if minimum clusters requirement is met
            $minClusters = config('media-processing.visual_analysis.require_min_clusters', 1);
            if (count($songClusters) < $minClusters) {
                Log::warning('Insufficient song clusters detected', [
                    'processing_id' => $this->processingLog->processing_id,
                    'clusters_found' => count($songClusters),
                    'min_required' => $minClusters,
                ]);

                $processingTime = microtime(true) - $startTime;

                // Store results but allow fallback to RMS-only
                $this->storeVisualAnalysis($visualSamples, $songClusters, $processingTime);

                return;
            }

            // Refine boundaries with dense sampling
            Log::info('Refining cluster boundaries with dense sampling', [
                'processing_id' => $this->processingLog->processing_id,
                'cluster_count' => count($songClusters),
            ]);

            foreach ($songClusters as &$cluster) {
                $refinement = $visualService->refineBoundaries($videoPath, $cluster);

                // Add refined boundaries to cluster
                $cluster['refined_visual_start'] = $refinement['refined_visual_start'];
                $cluster['refined_visual_end'] = $refinement['refined_visual_end'];
                $cluster['dense_sample_count'] = $refinement['dense_sample_count'];
            }
            unset($cluster); // Break reference

            $processingTime = microtime(true) - $startTime;

            // Store visual analysis results with refined boundaries
            $this->storeVisualAnalysis($visualSamples, $songClusters, $processingTime);

            Log::info('Visual analysis completed successfully', [
                'processing_id' => $this->processingLog->processing_id,
                'sample_count' => count($visualSamples),
                'song_clusters' => count($songClusters),
                'processing_time' => round($processingTime, 2).'s',
            ]);

        } catch (\Exception $e) {
            Log::error('Visual analysis failed', [
                'processing_id' => $this->processingLog->processing_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Check if fallback is enabled
            $fallbackEnabled = config('media-processing.visual_analysis.fallback_to_rms_on_failure', true);

            if ($fallbackEnabled) {
                Log::info('Visual analysis failed but fallback enabled, continuing with RMS-only', [
                    'processing_id' => $this->processingLog->processing_id,
                ]);

                // Store failure in processing log but don't fail the job
                $this->processingLog->update([
                    'visual_sample_count' => 0,
                    'visual_processing_time' => null,
                ]);

                return;
            }

            // If fallback is disabled, fail the job
            $this->processingLog->markAsFailed('Visual analysis failed: '.$e->getMessage());

            throw $e;
        }
    }

    /**
     * @param  array<int, array{
     *     timestamp: float,
     *     classification: string,
     *     confidence: float,
     *     brightness?: float,
     *     contrast?: float,
     *     edge_density?: float
     * }>  $visualSamples
     * @param  array<int, array{
     *     start_estimate: float,
     *     end_estimate: float,
     *     sample_count: int,
     *     samples?: array<int, float>,
     *     confidence?: float,
     *     refined_visual_start?: float,
     *     refined_visual_end?: float,
     *     dense_sample_count?: int
     * }>  $songClusters
     */
    private function storeVisualAnalysis(array $visualSamples, array $songClusters, float $processingTime): void
    {
        $this->processingLog->update([
            'visual_samples' => $visualSamples,
            'song_clusters' => $songClusters,
            'visual_sample_count' => count($visualSamples),
            'visual_processing_time' => $processingTime,
        ]);

        Log::info('Visual analysis results stored', [
            'processing_id' => $this->processingLog->processing_id,
            'sample_count' => count($visualSamples),
            'cluster_count' => count($songClusters),
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('PerformVisualAnalysis job failed permanently', [
            'processing_id' => $this->processingLog->processing_id,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);

        $fallbackEnabled = config('media-processing.visual_analysis.fallback_to_rms_on_failure', true);

        if ($fallbackEnabled) {
            Log::info('Visual analysis failed but fallback enabled, processing will continue', [
                'processing_id' => $this->processingLog->processing_id,
            ]);

            // Don't mark as failed, allow processing to continue
            return;
        }

        $this->processingLog->markAsFailed(
            'Visual analysis failed after '.$this->tries.' attempts: '.$exception->getMessage()
        );
    }
}
