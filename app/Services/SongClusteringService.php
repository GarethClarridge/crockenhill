<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class SongClusteringService
{
    private int $minSongDuration;

    private int $maxGapSeconds;

    private int $smoothingWindow;

    public function __construct()
    {
        $config = config('media-processing.visual_analysis', []);

        $this->minSongDuration = $config['min_song_duration'] ?? 60;
        $this->maxGapSeconds = $config['max_gap_seconds'] ?? 30;
        $this->smoothingWindow = $config['smoothing_window'] ?? 3;
    }

    /**
     * Cluster visual samples into song periods
     *
     * @param  array<array{timestamp: float, classification: string, confidence: float}>  $visualSamples
     * @return array<int, array<string, float|int|array<int, float>>>
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
     * @param  array<array{timestamp: float, classification: string, confidence: float}>  $samples
     * @return array<array{timestamp: float, classification: string, confidence: float}>
     */
    private function smoothClassifications(array $samples): array
    {
        if ($this->smoothingWindow <= 1 || count($samples) < $this->smoothingWindow) {
            return $samples;
        }

        $smoothed = [];
        $windowSize = $this->smoothingWindow;

        for ($i = 0; $i < count($samples); $i++) {
            // Look ahead in window
            $windowStart = max(0, $i - floor($windowSize / 2));
            $windowEnd = min(count($samples) - 1, $i + floor($windowSize / 2));

            $windowSamples = array_slice($samples, $windowStart, $windowEnd - $windowStart + 1);

            // Count classifications in window
            $songCount = count(array_filter($windowSamples, fn ($s) => $s['classification'] === 'song'));
            $speechCount = count($windowSamples) - $songCount;

            // Majority vote determines classification
            $classification = $songCount > $speechCount ? 'song' : 'speech';

            // Calculate average confidence for window
            $avgConfidence = array_sum(array_column($windowSamples, 'confidence')) / count($windowSamples);

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
     * @param  array<array{timestamp: float, classification: string, confidence: float}>  $samples
     * @return array<int, array<string, float|int|array<int, float>>>
     */
    private function groupConsecutiveSamples(array $samples): array
    {
        if (empty($samples)) {
            return [];
        }

        $clusters = [];
        $currentCluster = null;

        foreach ($samples as $sample) {
            // Only cluster SONG samples
            if ($sample['classification'] === 'song') {
                if ($currentCluster === null) {
                    // Start new cluster
                    $currentCluster = [
                        'start_estimate' => $sample['timestamp'],
                        'end_estimate' => $sample['timestamp'],
                        'sample_count' => 1,
                        'samples' => [$sample['timestamp']],
                        'confidences' => [$sample['confidence']],
                    ];
                } else {
                    // Add to existing cluster
                    $currentCluster['end_estimate'] = $sample['timestamp'];
                    $currentCluster['sample_count']++;
                    $currentCluster['samples'][] = $sample['timestamp'];
                    $currentCluster['confidences'][] = $sample['confidence'];
                }
            } else {
                // SPEECH sample - close current cluster if exists
                if ($currentCluster !== null) {
                    // Calculate average confidence
                    $currentCluster['confidence'] = array_sum($currentCluster['confidences']) / count($currentCluster['confidences']);
                    unset($currentCluster['confidences']); // Remove temporary array

                    $clusters[] = $currentCluster;
                    $currentCluster = null;
                }
            }
        }

        // Close any open cluster
        if ($currentCluster !== null) {
            $currentCluster['confidence'] = array_sum($currentCluster['confidences']) / count($currentCluster['confidences']);
            unset($currentCluster['confidences']);
            $clusters[] = $currentCluster;
        }

        return $clusters;
    }

    /**
     * Filter clusters by minimum duration
     *
     * @param  array<int, array<string, float|int|array<int, float>>>  $clusters
     * @return array<int, array<string, float|int|array<int, float>>>
     */
    private function filterByMinimumDuration(array $clusters, int $minDuration): array
    {
        return array_values(array_filter($clusters, function ($cluster) use ($minDuration) {
            $duration = $cluster['end_estimate'] - $cluster['start_estimate'];

            return $duration >= $minDuration;
        }));
    }

    /**
     * Merge clusters that are close together
     * Handles brief instrumental breaks where lyrics temporarily disappear
     *
     * @param  array<int, array<string, float|int|array<int, float>>>  $clusters
     * @return array<int, array<string, float|int|array<int, float>>>
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
                $currentCluster['end_estimate'] = $nextCluster['end_estimate'];
                $currentCluster['sample_count'] += $nextCluster['sample_count'];
                $currentCluster['samples'] = array_merge($currentCluster['samples'], $nextCluster['samples']);

                // Recalculate average confidence (weighted by sample count)
                $totalSamples = $currentCluster['sample_count'] + $nextCluster['sample_count'];
                $currentCluster['confidence'] = (
                    ($currentCluster['confidence'] * $currentCluster['sample_count']) +
                    ($nextCluster['confidence'] * $nextCluster['sample_count'])
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
