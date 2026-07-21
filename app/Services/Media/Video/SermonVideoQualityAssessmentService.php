<?php

declare(strict_types=1);

namespace App\Services\Media\Video;

use App\Data\SermonVideoQualityAssessmentResult;
use App\Enums\SermonVideoQualityStatus;
use App\Models\Sermon;
use App\Services\Processing\StorageAdapterHelper;
use App\Traits\SanitizesLogData;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * SermonVideoQualityAssessmentService
 *
 * A lightweight, explainable quality gate for identifying obvious failures in sermon videos.
 * Analyzes video files using frame-level luminance, variance, detail metrics, and temporal
 * frame similarity checks (such as frozen or blank screen detection) to ensure high-quality media.
 *
 * @phpstan-type FrameMetrics array{
 *     brightness: float,
 *     variance: float,
 *     detail_score: float,
 *     aggregate_score: float,
 *     blank: bool,
 *     low_detail: bool,
 * }
 * @phpstan-type FrozenWindowMetrics array{
 *     ratio: float,
 *     pair_count: int,
 * }
 */
class SermonVideoQualityAssessmentService
{
    use SanitizesLogData;

    private string $tempDisk;

    /**
     * Create a new video quality assessment service instance.
     *
     * @param  FrameExtractionService  $frameExtractionService  Service utilized to extract specific video frames
     * @param  StorageAdapterHelper  $storageHelper  Helper for storage interactions and fallback configurations
     */
    public function __construct(
        private readonly FrameExtractionService $frameExtractionService,
        private readonly StorageAdapterHelper $storageHelper,
    ) {
        $this->tempDisk = (string) config('thumbnail-generation.processing.temp_disk', 'local');
    }

    /**
     * Assess the visual quality of a sermon's video file.
     *
     * Resolves the video file from either local or S3 compatible storage, ensures a local
     * path is available, and executes frame-based analysis. Downloads from remote storage are
     * cleaned up automatically at the end of the operation.
     *
     * @param  Sermon  $sermon  The sermon model associated with the video
     * @param  string|null  $videoPath  Optional absolute/relative path override to the video file
     * @param  string|null  $disk  Optional storage disk override
     * @return SermonVideoQualityAssessmentResult The assessment result containing verdict, scores, and metrics
     *
     * @throws \Throwable If storage operations or FFmpeg analysis fails critically
     */
    public function assess(Sermon $sermon, ?string $videoPath = null, ?string $disk = null): SermonVideoQualityAssessmentResult
    {
        $videoPath ??= $sermon->video_file_path;
        $disk ??= (string) config('media-processing.storage.sermon_disk', 'public');

        if (! is_string($videoPath) || $videoPath === '') {
            return SermonVideoQualityAssessmentResult::failed('missing_video_path');
        }

        $downloadedVideoPath = null;

        try {
            if (! $this->frameExtractionService->videoFileExists($videoPath, $disk)) {
                return SermonVideoQualityAssessmentResult::failed('missing_video_file');
            }

            $localVideoPath = $this->frameExtractionService->ensureLocalVideoPath($videoPath, $disk);

            if ($disk && $this->storageHelper->isS3CompatibleDisk(Storage::disk($disk))) {
                $downloadedVideoPath = $localVideoPath;
            }

            return $this->assessLocalVideo($localVideoPath);
        } catch (\Throwable $e) {
            Log::warning('Sermon video quality assessment failed', $this->sanitizeArrayForLog([
                'sermon_id' => $sermon->id,
                'video_path' => $videoPath,
                'disk' => $disk,
                'error' => $e->getMessage(),
                'trace' => $this->sanitizeStackTrace($e->getTraceAsString()),
            ]));

            return SermonVideoQualityAssessmentResult::failed();
        } finally {
            $this->frameExtractionService->cleanupDownloadedVideo($downloadedVideoPath);
        }
    }

    /**
     * Assess video quality and, when the video was downloaded from S3, return the local temp path
     * so the caller can pass it to the next job (e.g. GenerateThumbnail) and avoid a second download.
     *
     * The caller is responsible for cleaning up the returned local path via
     * FrameExtractionService::cleanupDownloadedVideo().
     *
     * @param  Sermon  $sermon  The sermon model associated with the video
     * @param  string|null  $videoPath  Optional path override to the video file
     * @param  string|null  $disk  Optional storage disk override
     * @return array{result: SermonVideoQualityAssessmentResult, localVideoPath: string|null}
     *
     * @throws \Throwable If storage operations or FFmpeg analysis fails critically
     */
    public function assessAndRetainLocalPath(Sermon $sermon, ?string $videoPath = null, ?string $disk = null): array
    {
        $videoPath ??= $sermon->video_file_path;
        $disk ??= (string) config('media-processing.storage.sermon_disk', 'public');

        if (! is_string($videoPath) || $videoPath === '') {
            return ['result' => SermonVideoQualityAssessmentResult::failed('missing_video_path'), 'localVideoPath' => null];
        }

        try {
            if (! $this->frameExtractionService->videoFileExists($videoPath, $disk)) {
                return ['result' => SermonVideoQualityAssessmentResult::failed('missing_video_file'), 'localVideoPath' => null];
            }

            $localVideoPath = $this->frameExtractionService->ensureLocalVideoPath($videoPath, $disk);
            $isS3Download = $disk && $this->storageHelper->isS3CompatibleDisk(Storage::disk($disk));

            $result = $this->assessLocalVideo($localVideoPath);

            return [
                'result' => $result,
                'localVideoPath' => $isS3Download ? $localVideoPath : null,
            ];
        } catch (\Throwable $e) {
            Log::warning('Sermon video quality assessment failed', $this->sanitizeArrayForLog([
                'sermon_id' => $sermon->id,
                'video_path' => $videoPath,
                'disk' => $disk,
                'error' => $e->getMessage(),
                'trace' => $this->sanitizeStackTrace($e->getTraceAsString()),
            ]));

            return ['result' => SermonVideoQualityAssessmentResult::failed(), 'localVideoPath' => null];
        }
    }

    /**
     * Run quality assessment on a local video file.
     *
     * Extracts video duration from metadata, establishes coarse and burst sampling points,
     * extracts individual frame metrics and computes frozen screen ratios before compiling the result.
     *
     * @param  string  $localVideoPath  Absolute path to the local video file
     * @return SermonVideoQualityAssessmentResult The resolved assessment result containing the status verdict
     *
     * @throws \Exception If the metadata extraction or frame analysis fails critically
     */
    public function assessLocalVideo(string $localVideoPath): SermonVideoQualityAssessmentResult
    {
        $metadata = $this->frameExtractionService->getVideoMetadata($localVideoPath);
        $duration = max(0.0, (float) ($metadata['duration'] ?? 0.0));

        $coarseTimestamps = $this->coarseSampleTimestamps($duration);
        $burstTimestamps = $this->burstSampleTimestamps($duration);

        $coarseMetrics = $this->extractMetricsForTimestamps($localVideoPath, $coarseTimestamps);
        $frozenWindowMetrics = $this->frozenWindowMetrics($localVideoPath, $burstTimestamps);

        if ($coarseMetrics === []) {
            return SermonVideoQualityAssessmentResult::failed();
        }

        return $this->buildResult($coarseMetrics, $coarseTimestamps, $frozenWindowMetrics);
    }

    /**
     * @return list<float>
     */
    private function coarseSampleTimestamps(float $duration): array
    {
        $count = max(1, (int) config('media-processing.video_quality.sampling.coarse_sample_count', 8));

        if ($duration <= 0.0) {
            return [0.0];
        }

        $startRatio = $this->boundedRatio((float) config('media-processing.video_quality.sampling.middle_start_ratio', 0.2), 0.0, 0.9);
        $endRatio = $this->boundedRatio((float) config('media-processing.video_quality.sampling.middle_end_ratio', 0.8), $startRatio + 0.01, 1.0);

        $start = $duration * $startRatio;
        $end = $duration * $endRatio;

        if ($end <= $start) {
            $start = 0.0;
            $end = $duration;
        }

        if ($count === 1) {
            return [round(($start + $end) / 2, 3)];
        }

        $timestamps = [];
        $interval = ($end - $start) / ($count - 1);

        for ($index = 0; $index < $count; $index++) {
            $timestamps[] = round(min($duration, max(0.0, $start + ($interval * $index))), 3);
        }

        return array_values(array_unique($timestamps));
    }

    /**
     * @return list<list<float>>
     */
    private function burstSampleTimestamps(float $duration): array
    {
        if ($duration <= 0.0) {
            return [];
        }

        $windowCount = max(1, (int) config('media-processing.video_quality.sampling.burst_window_count', 2));
        $framesPerWindow = max(2, (int) config('media-processing.video_quality.sampling.burst_frames_per_window', 5));
        $gap = max(0.25, (float) config('media-processing.video_quality.sampling.burst_frame_gap_seconds', 1.5));
        $span = $gap * ($framesPerWindow - 1);

        $windows = [];
        for ($window = 1; $window <= $windowCount; $window++) {
            $center = $duration * ($window / ($windowCount + 1));
            $start = max(0.0, min($duration, $center - ($span / 2)));
            $timestamps = [];

            for ($index = 0; $index < $framesPerWindow; $index++) {
                $timestamps[] = round(min($duration, $start + ($gap * $index)), 3);
            }

            $windows[] = array_values(array_unique($timestamps));
        }

        return $windows;
    }

    /**
     * @param  list<float>  $timestamps
     * @return list<FrameMetrics>
     */
    private function extractMetricsForTimestamps(string $localVideoPath, array $timestamps): array
    {
        $metrics = [];

        foreach ($timestamps as $timestamp) {
            $framePath = $this->frameExtractionService->extractBaseFrame($localVideoPath, $timestamp);

            if (! is_string($framePath) || $framePath === '') {
                continue;
            }

            try {
                $frameMetrics = $this->analyzeFrame($framePath);

                if ($frameMetrics !== null) {
                    $metrics[] = $frameMetrics;
                }
            } finally {
                $this->cleanupFrame($framePath);
            }
        }

        return $metrics;
    }

    /**
     * @param  list<list<float>>  $burstWindows
     * @return list<FrozenWindowMetrics>
     */
    private function frozenWindowMetrics(string $localVideoPath, array $burstWindows): array
    {
        $windowMetrics = [];

        foreach ($burstWindows as $timestamps) {
            $previousFingerprint = null;
            $pairDiffs = [];

            foreach ($timestamps as $timestamp) {
                $framePath = $this->frameExtractionService->extractBaseFrame($localVideoPath, $timestamp);

                if (! is_string($framePath) || $framePath === '') {
                    continue;
                }

                try {
                    $fingerprint = $this->frameFingerprint($framePath);

                    if ($fingerprint !== [] && $previousFingerprint !== null) {
                        $pairDiffs[] = $this->fingerprintDiff($previousFingerprint, $fingerprint);
                    }

                    if ($fingerprint !== []) {
                        $previousFingerprint = $fingerprint;
                    }
                } finally {
                    $this->cleanupFrame($framePath);
                }
            }

            $windowMetrics[] = [
                'ratio' => $this->ratio($pairDiffs, static fn (float $diff): bool => $diff <= (float) config('media-processing.video_quality.thresholds.frozen_frame_diff', 0.01)),
                'pair_count' => count($pairDiffs),
            ];
        }

        return $windowMetrics;
    }

    /**
     * @return FrameMetrics|null
     */
    private function analyzeFrame(string $framePath): ?array
    {
        $image = $this->imageFromTempFrame($framePath);

        if (! $image instanceof \GdImage) {
            return null;
        }

        try {
            $width = imagesx($image);
            $height = imagesy($image);
            $stepX = max(1, (int) floor($width / 48));
            $stepY = max(1, (int) floor($height / 48));
            $luminanceValues = [];
            $detailAccumulator = 0.0;
            $previousRowLuminanceByColumn = [];

            for ($y = 0; $y < $height; $y += $stepY) {
                $previousColumnLuminance = null;

                for ($x = 0; $x < $width; $x += $stepX) {
                    $luminance = $this->pixelLuminance($image, $x, $y);
                    $luminanceValues[] = $luminance;

                    if ($previousColumnLuminance !== null) {
                        $detailAccumulator += abs($luminance - $previousColumnLuminance);
                    }

                    if (array_key_exists($x, $previousRowLuminanceByColumn)) {
                        $detailAccumulator += abs($luminance - $previousRowLuminanceByColumn[$x]);
                    }

                    $previousRowLuminanceByColumn[$x] = $luminance;
                    $previousColumnLuminance = $luminance;
                }
            }

            $sampleCount = count($luminanceValues);
            $averageBrightness = array_sum($luminanceValues) / ($sampleCount * 255);
            $variance = array_sum(array_map(
                static fn (float $value): float => (($value / 255) - $averageBrightness) ** 2,
                $luminanceValues,
            )) / $sampleCount;
            $detailScore = min(1.0, ($detailAccumulator / $sampleCount) / 80.0);
            $contrastScore = min(1.0, sqrt($variance) / 0.25);
            $brightnessScore = max(0.0, 1 - (abs($averageBrightness - 0.5) / 0.5));

            $blank = $averageBrightness <= (float) config('media-processing.video_quality.thresholds.blank_dark_brightness', 0.08)
                || $averageBrightness >= (float) config('media-processing.video_quality.thresholds.blank_light_brightness', 0.97)
                || ($variance <= (float) config('media-processing.video_quality.thresholds.blank_variance', 0.0005) && $detailScore <= (float) config('media-processing.video_quality.thresholds.low_detail_score', 0.04));

            $lowDetail = $detailScore <= (float) config('media-processing.video_quality.thresholds.low_detail_score', 0.04);

            return [
                'brightness' => round($averageBrightness, 6),
                'variance' => round($variance, 6),
                'detail_score' => round($detailScore, 6),
                'aggregate_score' => round(($brightnessScore * 0.25) + ($contrastScore * 0.35) + ($detailScore * 0.40), 6),
                'blank' => $blank,
                'low_detail' => $lowDetail,
            ];
        } finally {
            imagedestroy($image);
        }
    }

    /**
     * @param  list<FrameMetrics>  $coarseMetrics
     * @param  list<float>  $coarseTimestamps
     * @param  list<FrozenWindowMetrics>  $frozenWindowMetrics
     */
    private function buildResult(array $coarseMetrics, array $coarseTimestamps, array $frozenWindowMetrics): SermonVideoQualityAssessmentResult
    {
        $sampleCount = count($coarseMetrics);
        $blankFrameRatio = $this->ratio($coarseMetrics, static fn (array $metric): bool => $metric['blank']);
        $lowDetailRatio = $this->ratio($coarseMetrics, static fn (array $metric): bool => $metric['low_detail']);
        $frozenPairCount = (int) array_sum(array_column($frozenWindowMetrics, 'pair_count'));
        $frozenPairRatio = $frozenWindowMetrics === [] ? 0.0 : min(array_column($frozenWindowMetrics, 'ratio'));
        $aggregateScore = array_sum(array_column($coarseMetrics, 'aggregate_score')) / $sampleCount;
        $averageBrightness = array_sum(array_column($coarseMetrics, 'brightness')) / $sampleCount;
        $averageDetail = array_sum(array_column($coarseMetrics, 'detail_score')) / $sampleCount;
        $averageVariance = array_sum(array_column($coarseMetrics, 'variance')) / $sampleCount;

        [$status, $reason] = $this->verdict(
            blankFrameRatio: $blankFrameRatio,
            lowDetailRatio: $lowDetailRatio,
            frozenPairRatio: $frozenPairRatio,
            frozenPairCount: $frozenPairCount,
            averageBrightness: $averageBrightness,
        );

        return new SermonVideoQualityAssessmentResult(
            status: $status,
            reason: $reason,
            sampleCount: $sampleCount,
            sampleTimestamps: $coarseTimestamps,
            blankFrameRatio: round($blankFrameRatio, 6),
            frozenPairRatio: round($frozenPairRatio, 6),
            lowDetailRatio: round($lowDetailRatio, 6),
            aggregateScore: round($aggregateScore, 6),
            metrics: [
                'avg_brightness' => round($averageBrightness, 6),
                'avg_detail_score' => round($averageDetail, 6),
                'avg_luminance_variance' => round($averageVariance, 6),
                'frozen_pair_count' => $frozenPairCount,
                'frozen_window_count' => count($frozenWindowMetrics),
                'frozen_window_ratios' => array_map(
                    static fn (array $metrics): float => round($metrics['ratio'], 6),
                    $frozenWindowMetrics,
                ),
            ],
        );
    }

    /**
     * @return array{SermonVideoQualityStatus, string|null}
     */
    private function verdict(
        float $blankFrameRatio,
        float $lowDetailRatio,
        float $frozenPairRatio,
        int $frozenPairCount,
        float $averageBrightness,
    ): array {
        if ($blankFrameRatio >= (float) config('media-processing.video_quality.thresholds.blank_frame_ratio_reject', 0.75)) {
            return [
                SermonVideoQualityStatus::Rejected,
                $averageBrightness <= (float) config('media-processing.video_quality.thresholds.blank_dark_brightness', 0.08)
                    ? 'mostly_black'
                    : 'blank_screen',
            ];
        }

        if (
            $frozenPairCount >= 4
            && $frozenPairRatio >= (float) config('media-processing.video_quality.thresholds.frozen_pair_ratio_reject', 0.95)
        ) {
            if ((bool) config('media-processing.video_quality.auto_reject_frozen_frames', true)) {
                return [SermonVideoQualityStatus::Rejected, 'frozen_frames'];
            }

            return [SermonVideoQualityStatus::NeedsReview, 'frozen_frames'];
        }

        if ($lowDetailRatio >= (float) config('media-processing.video_quality.thresholds.low_detail_ratio_reject', 0.95)) {
            return [SermonVideoQualityStatus::Rejected, 'very_low_detail'];
        }

        if ($lowDetailRatio >= (float) config('media-processing.video_quality.thresholds.low_detail_ratio_review', 0.75)) {
            return [SermonVideoQualityStatus::NeedsReview, 'very_low_detail'];
        }

        return [SermonVideoQualityStatus::Approved, null];
    }

    /**
     * @return list<int>
     */
    private function frameFingerprint(string $framePath): array
    {
        $source = $this->imageFromTempFrame($framePath);

        if (! $source instanceof \GdImage) {
            return [];
        }

        $fingerprint = [];
        $target = imagecreatetruecolor(16, 16);

        try {
            imagecopyresampled($target, $source, 0, 0, 0, 0, 16, 16, imagesx($source), imagesy($source));

            for ($y = 0; $y < 16; $y++) {
                for ($x = 0; $x < 16; $x++) {
                    $fingerprint[] = (int) round($this->pixelLuminance($target, $x, $y));
                }
            }
        } finally {
            imagedestroy($target);
            imagedestroy($source);
        }

        return $fingerprint;
    }

    /**
     * @param  list<int>  $left
     * @param  list<int>  $right
     */
    private function fingerprintDiff(array $left, array $right): float
    {
        $count = min(count($left), count($right));

        if ($count === 0) {
            return 1.0;
        }

        $total = 0.0;
        for ($index = 0; $index < $count; $index++) {
            $total += abs($left[$index] - $right[$index]) / 255;
        }

        return $total / $count;
    }

    private function pixelLuminance(\GdImage $image, int $x, int $y): float
    {
        $rgb = (int) imagecolorat($image, $x, $y);
        $red = ($rgb >> 16) & 0xFF;
        $green = ($rgb >> 8) & 0xFF;
        $blue = $rgb & 0xFF;

        return (0.2126 * $red) + (0.7152 * $green) + (0.0722 * $blue);
    }

    private function imageFromTempFrame(string $framePath): ?\GdImage
    {
        $fullPath = Storage::disk($this->tempDisk)->path($framePath);
        $contents = @file_get_contents($fullPath);

        if (! is_string($contents) || $contents === '') {
            return null;
        }

        $image = @imagecreatefromstring($contents);

        return $image instanceof \GdImage ? $image : null;
    }

    private function cleanupFrame(string $framePath): void
    {
        try {
            if ((bool) config('thumbnail-generation.processing.cleanup_temp_files', true)) {
                Storage::disk($this->tempDisk)->delete($framePath);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to cleanup video-quality frame', $this->sanitizeArrayForLog([
                'frame_path' => $framePath,
                'error' => $e->getMessage(),
                'trace' => $this->sanitizeStackTrace($e->getTraceAsString()),
            ]));
        }
    }

    private function boundedRatio(float $value, float $min, float $max): float
    {
        return max($min, min($value, $max));
    }

    /**
     * @template T
     *
     * @param  list<T>  $items
     * @param  callable(T): bool  $predicate
     */
    private function ratio(array $items, callable $predicate): float
    {
        if ($items === []) {
            return 0.0;
        }

        $matches = 0;
        foreach ($items as $item) {
            if ($predicate($item)) {
                $matches++;
            }
        }

        return $matches / count($items);
    }
}
