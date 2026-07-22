<?php

declare(strict_types=1);

namespace App\Services\Media\Audio;

use App\Exceptions\SegmentationException;

/**
 * Statistical analysis of audio RMS (loudness) logs for segmentation.
 *
 * This service parses the raw text output from the FFmpeg `astats` metadata
 * filter to build a time-mapped dataset of loudness levels. It uses this
 * data to identify continuous "loud" periods (songs) and "quiet" periods
 * (speech) using either fixed or adaptive thresholding calibrated for
 * church recordings.
 */
class RmsAnalysisService
{
    /** Default RMS threshold (dB) — calibrated for church recordings with ambient noise. */
    private const float DEFAULT_RMS_THRESHOLD = -45.0;

    /** Minimum duration (seconds) for a loud section to be considered a distinct event. */
    private const float DEFAULT_MIN_SECTION_DURATION = 30.0;

    private const string PTS_TIME_PATTERN = '/pts_time:(\d+(?:\.\d+)?)/';

    private const string RMS_LEVEL_PATTERN = '/lavfi\.astats\.Overall\.RMS_level=(-?\d+(?:\.\d+)?|-inf)/';

    private readonly float $rmsThreshold;

    private readonly float $minSectionDuration;

    /** @var array<string, mixed> */
    private readonly array $adaptiveConfig;

    public function __construct()
    {
        $this->rmsThreshold = (float) config('media-processing.segmentation.rms_threshold', self::DEFAULT_RMS_THRESHOLD);
        $this->minSectionDuration = (float) config('media-processing.segmentation.min_section_duration', self::DEFAULT_MIN_SECTION_DURATION);
        $this->adaptiveConfig = config('media-processing.segmentation.adaptive_thresholds', []);
    }

    /**
     * Extract all RMS data with timestamps for segment calculation.
     *
     * Parses FFmpeg astats metadata lines to associate RMS levels with
     * precise PTS timestamps.
     *
     * @param  string  $logContent  The raw output from the FFmpeg astats filter
     * @return list<array{time: float, rms: float}> Dataset of PTS timestamps and RMS levels (dB)
     */
    public function extractRmsData(string $logContent): array
    {
        $lines = explode("\n", trim($logContent));
        $rmsData = [];
        $currentTime = 0.0;

        foreach ($lines as $line) {
            $ptsTime = $this->parsePtsTime($line);
            if ($ptsTime !== null) {
                $currentTime = $ptsTime;

                continue;
            }

            $rmsLevel = $this->parseRmsLevel($line);
            if ($rmsLevel !== null) {
                $rmsData[] = [
                    'time' => $currentTime,
                    'rms' => $rmsLevel,
                ];
            }
        }

        return $rmsData;
    }

    /**
     * Calculate actual average and peak RMS for a specific time segment.
     *
     * Filters the provided RMS dataset for entries within the time range
     * and calculates statistical metrics. Used for final segment metadata.
     *
     * @param  float  $startTime  Start timestamp in seconds
     * @param  float  $endTime  End timestamp in seconds
     * @param  list<array{time: float, rms: float}>  $rmsData  Dataset from extractRmsData()
     * @return array{avg: float, peak: float} Statistical summary (dB)
     */
    public function calculateSegmentRms(float $startTime, float $endTime, array $rmsData): array
    {
        $segmentRms = collect($rmsData)
            ->filter(fn (array $data) => $data['time'] >= $startTime && $data['time'] <= $endTime && $data['rms'] > -999.0)
            ->pluck('rms');

        if ($segmentRms->isEmpty()) {
            // Fallback values representing a quiet but not silent segment.
            return ['avg' => -50.0, 'peak' => -40.0];
        }

        $avgRms = $segmentRms->average();
        $peakRms = $segmentRms->max();

        return [
            'avg' => round((float) $avgRms, 1),
            'peak' => round((float) $peakRms, 1),
        ];
    }

    /**
     * Parse continuous audio sections that exceed the RMS threshold.
     *
     * Identifies "loud" periods in the audio by scanning the RMS log.
     * Sections shorter than the minimum duration are ignored to filter out
     * brief transients or noise spikes.
     *
     * @param  string  $logContent  The raw FFmpeg astats output
     * @param  float|null  $threshold  Optional override for the RMS threshold (dB)
     * @param  float|null  $minSectionDuration  Optional override for minimum duration (seconds)
     * @return list<array{start: float, end: float}> Identified loud spans
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
            $ptsTime = $this->parsePtsTime($line);
            if ($ptsTime !== null) {
                $currentTime = $ptsTime;

                continue;
            }

            $rmsLevel = $this->parseRmsLevel($line);
            if ($rmsLevel === null) {
                continue;
            }

            if ($rmsLevel > $threshold) {
                if ($currentSection === null) {
                    $currentSection = ['start' => $currentTime, 'end' => null];
                }

                continue;
            }

            if ($currentSection !== null) {
                $currentSection['end'] = $currentTime;
                if (($currentSection['end'] - $currentSection['start']) >= $minSectionDuration) {
                    $sections[] = $currentSection;
                }
                $currentSection = null;
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
     * Scans the log lines for the highest PTS timestamp. If no timestamps
     * are found, it falls back to an estimate based on the number of lines.
     *
     * @param  string  $logContent  The raw FFmpeg astats output
     * @param  list<string>  $lines  The log content split into lines
     * @return float Duration in seconds
     */
    public function getTotalDuration(string $logContent, array $lines): float
    {
        $maxTime = collect($lines)
            ->map(fn (string $line) => $this->parsePtsTime($line))
            ->filter(fn (?float $ptsTime) => ! is_null($ptsTime))
            ->max();

        if ($maxTime !== null && $maxTime > 0) {
            return (float) $maxTime;
        }

        // FFmpeg's astats output typically prints metadata at 43.06 fps (frame rate of the filter),
        // so we use 43.0 as a safe divisor for a duration estimate when timestamps are missing.
        return count($lines) / 43.0;
    }

    /**
     * Determine the optimal RMS threshold for segmentation.
     *
     * Prioritizes adaptive thresholding based on the specific audio file's
     * noise floor and signal strength. Falls back to a fixed global threshold
     * if adaptive calculation is disabled or fails.
     *
     * @param  string  $logContent  The raw FFmpeg astats output
     * @return array{
     *     threshold: float,
     *     method: 'fixed'|'adaptive'|'fallback',
     *     log_data: array{
     *         method: string,
     *         threshold_used: float,
     *         reason?: string,
     *         raw_threshold?: float,
     *         sample_count?: int,
     *         percentile_used?: float,
     *         bounds_applied?: bool,
     *     },
     *     rms_stats?: array{
     *         sample_count: int,
     *         min: float,
     *         max: float,
     *         mean: float,
     *         p25: float,
     *         p50: float,
     *         p75: float,
     *         adaptive_threshold: float,
     *     }
     * } The resolved threshold and diagnostic metadata
     *
     * @throws SegmentationException If adaptive calculation fails and fallback is disabled
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
                    'reason' => $adaptiveResult['error'],
                ],
            ];
        }

        throw new SegmentationException('Adaptive threshold calculation failed and fallback is disabled: '.$adaptiveResult['error']);
    }

    /**
     * Calculate an adaptive threshold based on the RMS distribution of the file.
     *
     * Analyzes the statistical distribution of RMS levels to find a threshold
     * that separates "silence/background" from "signal". Uses a configurable
     * percentile (e.g. 30th percentile) as the baseline for speech.
     *
     * @param  string  $logContent  The raw FFmpeg astats output
     * @return array{
     *     success: true,
     *     threshold: float,
     *     log_data: array{
     *         method: 'adaptive',
     *         threshold_used: float,
     *         raw_threshold: float,
     *         sample_count: int,
     *         percentile_used: float,
     *         bounds_applied: bool,
     *     },
     *     rms_stats: array{
     *         sample_count: int,
     *         min: float,
     *         max: float,
     *         mean: float,
     *         p25: float,
     *         p50: float,
     *         p75: float,
     *         adaptive_threshold: float,
     *     },
     * }|array{
     *     success: false,
     *     error: string,
     *     sample_count?: int,
     *     min_required?: int,
     * } Statistical analysis result
     */
    public function calculateAdaptiveThreshold(string $logContent): array
    {
        try {
            $rmsData = $this->extractRmsData($logContent);
            $rmsValues = array_filter(array_column($rmsData, 'rms'), fn (float $rms) => $rms > -999.0);

            $minSampleCount = $this->adaptiveConfig['min_sample_count'] ?? 1000;
            $sampleCount = count($rmsValues);

            if ($sampleCount < $minSampleCount) {
                return [
                    'success' => false,
                    'error' => 'insufficient_samples',
                    'sample_count' => $sampleCount,
                    'min_required' => $minSampleCount,
                ];
            }

            sort($rmsValues);

            $speechPercentile = ($this->adaptiveConfig['speech_percentile'] ?? 30) / 100.0;
            $speechThreshold = $rmsValues[(int) floor($sampleCount * $speechPercentile)];

            $minThreshold = $this->adaptiveConfig['min_threshold'] ?? -80.0;
            $maxThreshold = $this->adaptiveConfig['max_threshold'] ?? -20.0;

            $adaptiveThreshold = max($minThreshold, min($maxThreshold, $speechThreshold));

            return [
                'success' => true,
                'threshold' => $adaptiveThreshold,
                'log_data' => [
                    'method' => 'adaptive',
                    'threshold_used' => $adaptiveThreshold,
                    'raw_threshold' => $speechThreshold,
                    'sample_count' => $sampleCount,
                    'percentile_used' => $speechPercentile * 100,
                    'bounds_applied' => $speechThreshold !== $adaptiveThreshold,
                ],
                'rms_stats' => $this->calculateRmsStats($rmsValues, $adaptiveThreshold),
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Extract RMS values for specific target timestamps.
     *
     * Searches the RMS dataset for entries closest to each target timestamp
     * within a defined tolerance. Used for point-in-time audio analysis.
     *
     * @param  list<array{time: float, rms: float}>  $rmsData  Dataset from extractRmsData()
     * @param  list<float>  $timestamps  List of target timestamps in seconds
     * @return list<float> List of matching RMS levels (dB)
     */
    public function extractRmsForTimestamps(array $rmsData, array $timestamps): array
    {
        /** Window in seconds to search for a matching RMS entry around a target timestamp. */
        $tolerance = 5.0;

        $rmsCollection = collect($rmsData);

        /** @var list<float> */
        return collect($timestamps)
            ->map(function (float $targetTime) use ($rmsCollection, $tolerance): ?float {
                $match = $rmsCollection->first(fn (array $data) => abs($data['time'] - $targetTime) <= $tolerance && $data['rms'] > -999.0);

                return $match !== null ? (float) $match['rms'] : null;
            })
            ->filter(fn (?float $value) => ! is_null($value))
            ->values()
            ->all();
    }

    /**
     * Extract all RMS values within a specific time region.
     *
     * Returns a flat list of all RMS levels recorded between the start and
     * end times.
     *
     * @param  list<array{time: float, rms: float}>  $rmsData  Dataset from extractRmsData()
     * @param  float  $startTime  Start timestamp in seconds
     * @param  float  $endTime  End timestamp in seconds
     * @return list<float> List of RMS levels (dB)
     */
    public function extractRmsForRegion(array $rmsData, float $startTime, float $endTime): array
    {
        /** @var list<float> */
        return collect($rmsData)
            ->filter(fn (array $data) => $data['time'] >= $startTime && $data['time'] <= $endTime && $data['rms'] > -999.0)
            ->pluck('rms')
            ->values()
            ->all();
    }

    /**
     * Get the default (fixed) RMS threshold from configuration.
     */
    public function getRmsThreshold(): float
    {
        return $this->rmsThreshold;
    }

    /**
     * Get the minimum section duration from configuration.
     */
    public function getMinSectionDuration(): float
    {
        return $this->minSectionDuration;
    }

    private function parsePtsTime(string $line): ?float
    {
        if (preg_match(self::PTS_TIME_PATTERN, $line, $matches)) {
            return (float) $matches[1];
        }

        return null;
    }

    private function parseRmsLevel(string $line): ?float
    {
        if (preg_match(self::RMS_LEVEL_PATTERN, $line, $matches)) {
            return $matches[1] === '-inf' ? -999.0 : (float) $matches[1];
        }

        return null;
    }

    /**
     * @param  list<float>  $sortedRmsValues
     * @return array{
     *     sample_count: int,
     *     min: float,
     *     max: float,
     *     mean: float,
     *     p25: float,
     *     p50: float,
     *     p75: float,
     *     adaptive_threshold: float,
     * }
     */
    private function calculateRmsStats(array $sortedRmsValues, float $adaptiveThreshold): array
    {
        $count = count($sortedRmsValues);

        return [
            'sample_count' => $count,
            'min' => $sortedRmsValues[0],
            'max' => $sortedRmsValues[$count - 1],
            'mean' => array_sum($sortedRmsValues) / $count,
            'p25' => $sortedRmsValues[(int) floor($count * 0.25)],
            'p50' => $sortedRmsValues[(int) floor($count * 0.50)],
            'p75' => $sortedRmsValues[(int) floor($count * 0.75)],
            'adaptive_threshold' => $adaptiveThreshold,
        ];
    }
}
