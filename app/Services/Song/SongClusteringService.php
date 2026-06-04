<?php

declare(strict_types=1);

namespace App\Services\Song;

use App\Enums\LivestreamSegmentClassification;
use Illuminate\Support\Facades\Log;

/**
 * @phpstan-type VisualSample array{
 *     timestamp: float,
 *     classification: string,
 *     confidence: float,
 *     brightness?: float,
 *     contrast?: float,
 *     edge_density?: float,
 *     ylow?: float,
 *     percentile_span?: float,
 *     detection_mode?: 'old_style'|'new_style'|'none'
 * }
 * @phpstan-type SongCluster array{
 *     start_estimate: float,
 *     end_estimate: float,
 *     sample_count: int,
 *     samples: list<float>,
 *     confidence: float
 * }
 */
class SongClusteringService
{
    private const MIN_SONG_DURATION = 60;

    private const MAX_GAP_SECONDS = 30;

    private const SMOOTHING_WINDOW = 3;

    private int $minSongDuration;

    private int $maxGapSeconds;

    private int $smoothingWindow;

    public function __construct(
        int $minSongDuration = self::MIN_SONG_DURATION,
        int $maxGapSeconds = self::MAX_GAP_SECONDS,
        int $smoothingWindow = self::SMOOTHING_WINDOW,
    ) {
        $this->minSongDuration = $minSongDuration;
        $this->maxGapSeconds = $maxGapSeconds;
        $this->smoothingWindow = $smoothingWindow;
    }

    /**
     * Cluster visual samples into song periods
     *
     * @param  list<VisualSample>  $visualSamples
     * @return list<SongCluster>
     */
    public function clusterSongPeriods(array $visualSamples): array
    {
        if (empty($visualSamples)) {
            return [];
        }

        try {
            Log::info('Starting song clustering', [
                'total_samples' => count($visualSamples),
            ]);

            // Step 1: Smooth classifications to reduce flickering
            $smoothedSamples = $this->smoothClassifications($visualSamples);

            // Step 2: Group consecutive SONG samples
            $rawClusters = $this->groupConsecutiveSamples($smoothedSamples);

            // Step 3: Merge close gaps (brief transitions between verses)
            $mergedClusters = $this->mergeCloseGaps($rawClusters);

            // Step 4: Filter by minimum duration
            $filteredClusters = $this->filterByMinimumDuration($mergedClusters, $this->minSongDuration);

            Log::info('Song clustering complete', [
                'raw_clusters' => count($rawClusters),
                'merged_clusters' => count($mergedClusters),
                'final_clusters' => count($filteredClusters),
            ]);

            return $filteredClusters;

        } catch (\Exception $e) {
            Log::error('Song clustering failed', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Smooth classifications to reduce flickering
     * Requires N consecutive samples of same type to change state
     *
     * @param  list<VisualSample>  $samples
     * @return list<VisualSample>
     */
    private function smoothClassifications(array $samples): array
    {
        if ($this->smoothingWindow <= 1 || count($samples) < $this->smoothingWindow) {
            return $samples;
        }

        $smoothed = [];
        $windowSize = $this->smoothingWindow;
        $sampleCount = count($samples);
        $halfWindow = intdiv($windowSize, 2);

        for ($i = 0; $i < $sampleCount; $i++) {
            // Look ahead in window
            $windowStart = max(0, $i - $halfWindow);
            $windowEnd = min($sampleCount - 1, $i + $halfWindow);
            $windowLength = ($windowEnd - $windowStart) + 1;

            $windowSamples = array_slice($samples, $windowStart, $windowLength);
            if ($windowSamples === []) {
                continue;
            }

            // Count classifications in window
            $songCount = count(array_filter($windowSamples, fn (array $sample): bool => $sample['classification'] === LivestreamSegmentClassification::Song->value));
            $windowSampleCount = count($windowSamples);
            $speechCount = $windowSampleCount - $songCount;

            // Majority vote determines classification
            $classification = $songCount > $speechCount ? LivestreamSegmentClassification::Song->value : LivestreamSegmentClassification::Speech->value;

            // Calculate average confidence for window
            $avgConfidence = array_sum(array_column($windowSamples, 'confidence')) / $windowSampleCount;

            $smoothed[] = [
                'timestamp' => $samples[$i]['timestamp'],
                'classification' => $classification,
                'confidence' => round($avgConfidence, 3),
            ];
        }

        return $smoothed;
    }

    /**
     * Group consecutive samples with same classification
     *
     * @param  list<VisualSample>  $samples
     * @return list<SongCluster>
     */
    private function groupConsecutiveSamples(array $samples): array
    {
        if (empty($samples)) {
            return [];
        }

        $clusters = [];
        $currentStart = 0.0;
        $currentEnd = 0.0;
        $currentSampleCount = 0;
        $currentSamples = [];
        $currentConfidenceSum = 0.0;
        $inCluster = false;

        foreach ($samples as $sample) {
            // Only cluster SONG samples
            if ($sample['classification'] === LivestreamSegmentClassification::Song->value) {
                if (! $inCluster) {
                    // Start new cluster
                    $currentStart = $sample['timestamp'];
                    $currentEnd = $sample['timestamp'];
                    $currentSampleCount = 1;
                    $currentSamples = [$sample['timestamp']];
                    $currentConfidenceSum = $sample['confidence'];
                    $inCluster = true;
                } else {
                    // Add to existing cluster
                    $currentEnd = $sample['timestamp'];
                    $currentSampleCount++;
                    $currentSamples[] = $sample['timestamp'];
                    $currentConfidenceSum += $sample['confidence'];
                }
            } else {
                // SPEECH sample - close current cluster if exists
                if ($inCluster) {
                    $clusters[] = [
                        'start_estimate' => $currentStart,
                        'end_estimate' => $currentEnd,
                        'sample_count' => $currentSampleCount,
                        'samples' => $currentSamples,
                        'confidence' => $currentConfidenceSum / $currentSampleCount,
                    ];

                    $inCluster = false;
                }
            }
        }

        // Close any open cluster
        if ($inCluster) {
            $clusters[] = [
                'start_estimate' => $currentStart,
                'end_estimate' => $currentEnd,
                'sample_count' => $currentSampleCount,
                'samples' => $currentSamples,
                'confidence' => $currentConfidenceSum / $currentSampleCount,
            ];
        }

        return $clusters;
    }

    /**
     * Filter clusters by minimum duration
     *
     * @param  list<SongCluster>  $clusters
     * @return list<SongCluster>
     */
    private function filterByMinimumDuration(array $clusters, int $minDuration): array
    {
        return array_values(array_filter($clusters, function (array $cluster) use ($minDuration): bool {
            $duration = $cluster['end_estimate'] - $cluster['start_estimate'];

            return $duration >= $minDuration;
        }));
    }

    /**
     * Merge clusters that are close together
     * Handles brief instrumental breaks where lyrics temporarily disappear
     *
     * @param  list<SongCluster>  $clusters
     * @return list<SongCluster>
     */
    private function mergeCloseGaps(array $clusters, ?int $maxGap = null): array
    {
        if (count($clusters) <= 1) {
            return $clusters;
        }

        $maxGap = $maxGap ?? $this->maxGapSeconds;
        $merged = [];
        $currentCluster = $clusters[0];

        for ($i = 1; $i < count($clusters); $i++) {
            $nextCluster = $clusters[$i];
            $gap = $nextCluster['start_estimate'] - $currentCluster['end_estimate'];

            if ($gap <= $maxGap) {
                // Merge clusters
                $currentSampleCount = $currentCluster['sample_count'];
                $nextSampleCount = $nextCluster['sample_count'];
                $totalSamples = $currentSampleCount + $nextSampleCount;

                $currentCluster['end_estimate'] = $nextCluster['end_estimate'];
                $currentCluster['sample_count'] = $totalSamples;
                $currentCluster['samples'] = array_merge($currentCluster['samples'], $nextCluster['samples']);

                // Recalculate average confidence (weighted by sample count)
                $currentCluster['confidence'] = (
                    ($currentCluster['confidence'] * $currentSampleCount) +
                    ($nextCluster['confidence'] * $nextSampleCount)
                ) / $totalSamples;
            } else {
                // Gap too large, save current and start new
                $merged[] = $currentCluster;
                $currentCluster = $nextCluster;
            }
        }

        // Add final cluster
        $merged[] = $currentCluster;

        return $merged;
    }
}
