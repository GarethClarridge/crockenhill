<?php

declare(strict_types=1);

namespace App\Services\Media\Video;

use App\Data\SermonVideoQualityAssessmentResult;
use App\Data\VideoDeadPictureCoverage;
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
 * It measures freeze and black time with ffmpeg's own detectors over the whole
 * file. Under the operator's release share of usable picture the video is
 * hidden and the sermon goes out audio-only; otherwise it is released whole,
 * flagged when any of it is dead. Nothing is trimmed and nothing waits for a
 * person (ruling 2026-09-22).
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

        $coverage = $this->deadPictureProbe->probe($localVideoPath, $duration);

        /*
         * Nothing measured is no evidence. A recording whose picture cannot be
         * read at all must not pass as a healthy one, so it records the failure
         * and stays eligible for reassessment.
         */
        if ($coverage === null) {
            return SermonVideoQualityAssessmentResult::failed();
        }

        [$status, $reason] = $this->verdict($coverage);

        return new SermonVideoQualityAssessmentResult(
            status: $status,
            reason: $reason,
            durationSeconds: round($coverage->durationSeconds, 3),
            deadSeconds: $coverage->deadSeconds,
            usableShare: round($coverage->usableShare(), 6),
            freezeSeconds: $coverage->freezeSeconds,
            blackSeconds: $coverage->blackSeconds,
            metrics: ['dead_intervals' => $coverage->deadIntervals],
        );
    }

    /**
     * Operator ruling 2026-09-22: a video is released whole or not at all.
     *
     * - Under the release share of usable picture, it is rejected: the video is
     *   hidden and the sermon is released audio-only.
     * - At or over it, with any dead picture, it is approved and released whole,
     *   carrying a `partially_*` reason as its "has video issues" flag.
     * - With no dead picture, it is approved clean.
     *
     * Nothing waits for a person: the verdict is final either way, and the flag
     * records the imperfection rather than asking for a decision.
     *
     * @return array{SermonVideoQualityStatus, string|null}
     */
    private function verdict(VideoDeadPictureCoverage $coverage): array
    {
        if ($coverage->deadSeconds <= 0.0) {
            return [SermonVideoQualityStatus::Approved, null];
        }

        $blackDominates = $coverage->blackSeconds >= $coverage->deadSeconds * 0.5;
        $releaseShare = (float) config('media-processing.video_quality.thresholds.release_usable_share', 0.75);

        if ($coverage->usableShare() < $releaseShare) {
            return [
                SermonVideoQualityStatus::Rejected,
                $blackDominates ? 'mostly_black' : 'frozen_frames',
            ];
        }

        return [
            SermonVideoQualityStatus::Approved,
            $blackDominates ? 'partially_black' : 'partially_frozen',
        ];
    }
}
