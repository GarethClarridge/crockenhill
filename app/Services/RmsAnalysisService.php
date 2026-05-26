<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\SegmentationException;
use Illuminate\Support\Facades\Log;

class RmsAnalysisService
{
    private float $rmsThreshold;

    private float $minSectionDuration;

    /** @var array<string, mixed> */
    private array $adaptiveConfig;

    public function __construct()
    {
        $this->rmsThreshold = config('media-processing.segmentation.rms_threshold', -45.0);
        $this->minSectionDuration = config('media-processing.segmentation.min_section_duration', 30.0);
        $this->adaptiveConfig = config('media-processing.segmentation.adaptive_thresholds', []);
    }

    /**
     * Extract all RMS data with timestamps for segment calculation
     *
     * @return array<array{time: float, rms: float}>
     */
    public function extractRmsData(string $logContent): array
    {
        $lines = explode("\n", trim($logContent));
        $rmsData = [];
        $currentTime = 0.0;

        foreach ($lines as $line) {
            if (preg_match('/pts_time:(\d+(?:\.\d+)?)/', $line, $timeMatches)) {
                $currentTime = (float) $timeMatches[1];

                continue;
            }

            if (preg_match('/lavfi\.astats\.Overall\.RMS_level=(-?\d+(?:\.\d+)?|-inf)/', $line, $matches)) {
                $rmsLevel = $matches[1] === '-inf' ? -999.0 : (float) $matches[1];

                $rmsData[] = [
                    'time' => $currentTime,
                    'rms' => $rmsLevel,
                ];
            }
        }

        return $rmsData;
    }

    /**
     * Calculate actual average and peak RMS for a segment
     *
     * @param  array<array{time: float, rms: float}>  $rmsData
     * @return array{avg: float, peak: float}
     */
    public function calculateSegmentRms(float $startTime, float $endTime, array $rmsData): array
    {
        $segmentRms = [];

        foreach ($rmsData as $data) {
            if ($data['time'] >= $startTime && $data['time'] <= $endTime && $data['rms'] > -999.0) {
                $segmentRms[] = $data['rms'];
            }
        }

        if (empty($segmentRms)) {
            return ['avg' => -50.0, 'peak' => -40.0];
        }

        $avgRms = array_sum($segmentRms) / count($segmentRms);
        $peakRms = max($segmentRms);

        return [
            'avg' => round($avgRms, 1),
            'peak' => round($peakRms, 1),
        ];
    }

    /**
     * Parse audio sections from RMS log content
     *
     * @return array<array{start: float, end: float}>
     */
    public function parseAudioSections(string $logContent, ?float $threshold = null, ?float $minSectionDuration = null): array
    {
        $threshold = $threshold ?? $this->rmsThreshold;
        $minSectionDuration = $minSectionDuration ?? $this->minSectionDuration;

        $lines = explode("\n", trim($logContent));

        $totalDuration = $this->getTotalDuration($logContent, $lines);

        $sections = [];
        $currentSection = null;
        $currentTime = 0.0;

        foreach ($lines as $line) {
            if (preg_match('/pts_time:(\d+(?:\.\d+)?)/', $line, $timeMatches)) {
                $currentTime = (float) $timeMatches[1];

                continue;
            }

            if (preg_match('/lavfi\.astats\.Overall\.RMS_level=(-?\d+(?:\.\d+)?|-inf)/', $line, $rmsMatches)) {
                $rmsLevel = $rmsMatches[1] === '-inf' ? -999.0 : (float) $rmsMatches[1];

                if ($rmsLevel > $threshold) {
                    if ($currentSection === null) {
                        $currentSection = ['start' => $currentTime, 'end' => null];
                    }
                } else {
                    if ($currentSection !== null) {
                        $currentSection['end'] = $currentTime;
                        if (($currentSection['end'] - $currentSection['start']) >= $minSectionDuration) {
                            $sections[] = $currentSection;
                        }
                        $currentSection = null;
                    }
                }
            }
        }

        if ($currentSection !== null) {
            $currentSection['end'] = $totalDuration;
            if (($currentSection['end'] - $currentSection['start']) >= $minSectionDuration) {
                $sections[] = $currentSection;
            }
        }

        return $sections;
    }

    /**
     * Get total duration from RMS log content.
     *
     * @param  array<int, string>  $lines
     */
    public function getTotalDuration(string $logContent, array $lines): float
    {
        $maxTime = 0.0;
        foreach ($lines as $line) {
            if (preg_match('/pts_time:(\d+(?:\.\d+)?)/', $line, $matches)) {
                $maxTime = max($maxTime, (float) $matches[1]);
            }
        }

        if ($maxTime > 0) {
            return $maxTime;
        }

        return count($lines) / 43.0;
    }

    /**
     * Determine which threshold to use based on configuration
     *
     * @return array<string, mixed>
     */
    public function determineThreshold(string $logContent): array
    {
        if (! ($this->adaptiveConfig['enabled'] ?? true)) {
            return [
                'threshold' => $this->rmsThreshold,
                'method' => 'fixed',
                'log_data' => [
                    'method' => 'fixed',
                    'threshold_used' => $this->rmsThreshold,
                    'reason' => 'adaptive_disabled',
                ],
            ];
        }

        $adaptiveResult = $this->calculateAdaptiveThreshold($logContent);

        if ($adaptiveResult['success']) {
            return [
                'threshold' => $adaptiveResult['threshold'],
                'method' => 'adaptive',
                'log_data' => $adaptiveResult['log_data'],
                'rms_stats' => $adaptiveResult['rms_stats'],
            ];
        }

        if ($this->adaptiveConfig['fallback_enabled'] ?? true) {
            return [
                'threshold' => $this->rmsThreshold,
                'method' => 'fallback',
                'log_data' => [
                    'method' => 'fallback',
                    'threshold_used' => $this->rmsThreshold,
                    'reason' => $adaptiveResult['error'] ?? 'adaptive_calculation_failed',
                ],
            ];
        }

        throw new SegmentationException('Adaptive threshold calculation failed and fallback is disabled: '.($adaptiveResult['error'] ?? 'unknown_error'));
    }

    /**
     * Calculate adaptive threshold based on RMS distribution
     *
     * @return array<string, mixed>
     */
    public function calculateAdaptiveThreshold(string $logContent): array
    {
        try {
            $rmsValues = [];
            $lines = explode("\n", trim($logContent));

            foreach ($lines as $line) {
                if (preg_match('/lavfi\.astats\.Overall\.RMS_level=(-?\d+(?:\.\d+)?|-inf)/', $line, $matches)) {
                    $rmsLevel = $matches[1] === '-inf' ? -999.0 : (float) $matches[1];
                    if ($rmsLevel > -999.0) {
                        $rmsValues[] = $rmsLevel;
                    }
                }
            }

            $minSampleCount = $this->adaptiveConfig['min_sample_count'] ?? 1000;
            if (count($rmsValues) < $minSampleCount) {
                return [
                    'success' => false,
                    'error' => 'insufficient_samples',
                    'sample_count' => count($rmsValues),
                    'min_required' => $minSampleCount,
                ];
            }

            sort($rmsValues);
            $count = count($rmsValues);

            $speechPercentile = ($this->adaptiveConfig['speech_percentile'] ?? 30) / 100.0;
            $speechThreshold = $rmsValues[(int) floor($count * $speechPercentile)];

            $minThreshold = $this->adaptiveConfig['min_threshold'] ?? -80.0;
            $maxThreshold = $this->adaptiveConfig['max_threshold'] ?? -20.0;

            $adaptiveThreshold = max($minThreshold, min($maxThreshold, $speechThreshold));

            $mean = array_sum($rmsValues) / $count;
            $p25 = $rmsValues[(int) floor($count * 0.25)];
            $p50 = $rmsValues[(int) floor($count * 0.50)];
            $p75 = $rmsValues[(int) floor($count * 0.75)];
            $minValue = $rmsValues[0];
            $maxValue = $rmsValues[$count - 1];

            return [
                'success' => true,
                'threshold' => $adaptiveThreshold,
                'log_data' => [
                    'method' => 'adaptive',
                    'threshold_used' => $adaptiveThreshold,
                    'raw_threshold' => $speechThreshold,
                    'sample_count' => $count,
                    'percentile_used' => $speechPercentile * 100,
                    'bounds_applied' => $speechThreshold !== $adaptiveThreshold,
                ],
                'rms_stats' => [
                    'sample_count' => $count,
                    'min' => $minValue,
                    'max' => $maxValue,
                    'mean' => $mean,
                    'p25' => $p25,
                    'p50' => $p50,
                    'p75' => $p75,
                    'adaptive_threshold' => $adaptiveThreshold,
                ],
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Extract RMS values for specific timestamps
     *
     * @param  array<array{time: float, rms: float}>  $rmsData
     * @param  array<float>  $timestamps
     * @return array<float>
     */
    public function extractRmsForTimestamps(array $rmsData, array $timestamps): array
    {
        $values = [];
        $tolerance = 5.0;

        foreach ($timestamps as $targetTime) {
            foreach ($rmsData as $data) {
                if (abs($data['time'] - $targetTime) <= $tolerance && $data['rms'] > -999.0) {
                    $values[] = $data['rms'];
                    break;
                }
            }
        }

        return $values;
    }

    /**
     * Extract RMS values for a time region
     *
     * @param  array<array{time: float, rms: float}>  $rmsData
     * @return array<float>
     */
    public function extractRmsForRegion(array $rmsData, float $startTime, float $endTime): array
    {
        $values = [];

        foreach ($rmsData as $data) {
            if ($data['time'] >= $startTime && $data['time'] <= $endTime && $data['rms'] > -999.0) {
                $values[] = $data['rms'];
            }
        }

        return $values;
    }

    public function getRmsThreshold(): float
    {
        return $this->rmsThreshold;
    }

    public function getMinSectionDuration(): float
    {
        return $this->minSectionDuration;
    }
}
