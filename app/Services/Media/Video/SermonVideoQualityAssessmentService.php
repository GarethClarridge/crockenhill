<?php

declare(strict_types=1);

namespace App\Services\Media\Video;

use App\Data\SermonVideoQualityAssessmentResult;
use App\Data\VideoDeadPictureWindow;
use App\Enums\SermonVideoQualityStatus;
use App\Models\Sermon;
use App\Services\Processing\StorageAdapterHelper;
use App\Traits\SanitizesLogData;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Small, explainable video-quality gate for obvious sermon video failures.
 *
 * The gate asks one question: how much of this recording has no usable picture?
 * It measures freeze and black time with ffmpeg's own detectors over windows
 * spread across the whole file, and lets the *coverage* of that dead time decide
 * how far the verdict may go — a recording dead throughout is rejected and
 * hidden, one dead in part goes to review, and everything else is approved.
 *
 * The detector this replaced judged appearance instead of duration: frames
 * 1.5 s apart that looked alike, or an absolute brightness floor. Both misread
 * ordinary preaching — a static camera on a small subject barely changes in
 * 1.5 s, and dimly lit staging sits under the floor — and wrongly hid 27 of the
 * 47 historic sermon videos it rejected (plan §4.1b/§4.3a, 2026-09-14).
 */
class SermonVideoQualityAssessmentService
{
    use SanitizesLogData;

    public function __construct(
        private readonly FrameExtractionService $frameExtractionService,
        private readonly StorageAdapterHelper $storageHelper,
        private readonly VideoDeadPictureProbe $deadPictureProbe,
    ) {}

    /**
     * @throws \Throwable
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
     * @return array{result: SermonVideoQualityAssessmentResult, localVideoPath: string|null}
     *
     * @throws \Throwable
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
     * @throws \Exception
     */
    public function assessLocalVideo(string $localVideoPath): SermonVideoQualityAssessmentResult
    {
        $metadata = $this->frameExtractionService->getVideoMetadata($localVideoPath);
        $duration = max(0.0, (float) ($metadata['duration'] ?? 0.0));

        $windows = $this->deadPictureProbe->probe($localVideoPath, $duration);

        /*
         * No window measured is no evidence. A recording whose picture cannot be
         * read at all must not pass as a healthy one, so it records the failure
         * and stays eligible for reassessment.
         */
        if ($windows === []) {
            return SermonVideoQualityAssessmentResult::failed();
        }

        return $this->buildResult($windows);
    }

    /**
     * @param  list<VideoDeadPictureWindow>  $windows
     */
    private function buildResult(array $windows): SermonVideoQualityAssessmentResult
    {
        $minimumDeadRatio = (float) config('media-processing.video_quality.thresholds.dead_window_seconds_ratio', 0.5);

        $deadWindows = array_values(array_filter(
            $windows,
            static fn (VideoDeadPictureWindow $window): bool => $window->isDead($minimumDeadRatio),
        ));

        $deadWindowRatio = count($deadWindows) / count($windows);
        $freezeSeconds = array_sum(array_map(static fn (VideoDeadPictureWindow $window): float => $window->freezeSeconds, $windows));
        $blackSeconds = array_sum(array_map(static fn (VideoDeadPictureWindow $window): float => $window->blackSeconds, $windows));
        $measuredSeconds = array_sum(array_map(static fn (VideoDeadPictureWindow $window): float => $window->length, $windows));
        $deadSeconds = array_sum(array_map(static fn (VideoDeadPictureWindow $window): float => $window->deadSeconds(), $deadWindows));

        [$status, $reason] = $this->verdict($deadWindowRatio, $deadSeconds, $blackSeconds);

        return new SermonVideoQualityAssessmentResult(
            status: $status,
            reason: $reason,
            windowCount: count($windows),
            deadWindowCount: count($deadWindows),
            deadWindowRatio: round($deadWindowRatio, 6),
            freezeSeconds: round($freezeSeconds, 3),
            blackSeconds: round($blackSeconds, 3),
            measuredSeconds: round($measuredSeconds, 3),
            metrics: [
                'windows' => array_map(
                    static fn (VideoDeadPictureWindow $window): array => $window->toArray(),
                    $windows,
                ),
            ],
        );
    }

    /**
     * Coverage decides how far the verdict may go.
     *
     * Rejection hides the video from the public page, so it is reserved for a
     * recording whose picture is dead throughout — the whole-recording black
     * screens and holding cards. A recording that is dead only in part carries
     * preaching someone can watch, so it is flagged for a person to judge
     * rather than withheld automatically (plan §4.3a).
     *
     * @return array{SermonVideoQualityStatus, string|null}
     */
    private function verdict(float $deadWindowRatio, float $deadSeconds, float $blackSeconds): array
    {
        $blackDominates = $deadSeconds > 0.0 && $blackSeconds >= $deadSeconds * 0.5;

        if ($deadWindowRatio >= (float) config('media-processing.video_quality.thresholds.dead_window_ratio_reject', 0.75)) {
            return [
                SermonVideoQualityStatus::Rejected,
                $blackDominates ? 'mostly_black' : 'frozen_frames',
            ];
        }

        if ($deadWindowRatio >= (float) config('media-processing.video_quality.thresholds.dead_window_ratio_review', 0.01)) {
            return [
                SermonVideoQualityStatus::NeedsReview,
                $blackDominates ? 'partially_black' : 'partially_frozen',
            ];
        }

        return [SermonVideoQualityStatus::Approved, null];
    }
}
