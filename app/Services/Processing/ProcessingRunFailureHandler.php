<?php

declare(strict_types=1);

namespace App\Services\Processing;

use App\Contracts\ProvidesSafeMessage;
use App\Enums\ProcessingStatus;
use App\Mail\LivestreamProcessingFailed;
use App\Models\MediaProcessingLog;
use App\Services\Media\Video\VideoStorageService;
use App\Traits\SanitizesLogData;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Terminal error handler for the media processing pipeline.
 *
 * This service orchestrates the cleanup and state transitions required when a
 * background media processing run fails. It ensures that temporary files are
 * removed (to prevent disk exhaustion) and that administrators are notified
 * of critical failures via email.
 */
class ProcessingRunFailureHandler
{
    use SanitizesLogData;

    /** Failure profile for standard audio processing runs. */
    public const PROFILE_AUDIO = 'audio';

    /** Failure profile for standard video processing runs. */
    public const PROFILE_VIDEO = 'video';

    /** Failure profile for video auto-trim runs (segmentation-based). */
    public const PROFILE_VIDEO_AUTO_TRIM = 'video_auto_trim';

    /** Failure profile for livestream processing runs (segmentation-based + email alerts). */
    public const PROFILE_LIVESTREAM = 'livestream';

    public function __construct(
        private readonly VideoStorageService $storageService,
        private readonly MediaProcessingRunTransitionService $processingRunTransitions,
    ) {}

    /**
     * Handle a processing run failure by transitioning state and performing cleanup.
     *
     * Identifies the related MediaProcessingLog and dispatches the failure
     * logic appropriate for the given profile. If the run has already been
     * cancelled, failure handling is skipped to prevent state corruption.
     *
     * @param  string  $processingId  The unique identifier of the failed processing run
     * @param  \Throwable  $exception  The exception that triggered the failure
     * @param  string  $profile  The pipeline profile (audio, video, video_auto_trim, livestream)
     */
    public function handle(string $processingId, \Throwable $exception, string $profile): void
    {
        Log::error('Processing run failure', $this->sanitizeArrayForLog([
            'processing_id' => $processingId,
            'profile' => $profile,
            'error' => $exception->getMessage(),
            'trace' => $this->sanitizeStackTrace($exception->getTraceAsString()),
        ]));

        $processingLog = MediaProcessingLog::query()
            ->where('processing_id', $processingId)
            ->first();

        if (! $processingLog instanceof MediaProcessingLog) {
            return;
        }

        $processingLog = $processingLog->fresh() ?? $processingLog;

        if ($processingLog->isCancelled()) {
            Log::info('Skipping failure handling for cancelled processing run', $this->sanitizeArrayForLog([
                'processing_id' => $processingId,
                'profile' => $profile,
            ]));

            return;
        }

        $message = $this->safeMessage($exception, $profile);

        match ($profile) {
            self::PROFILE_AUDIO => $this->markAudioFailure($processingLog, $message),
            self::PROFILE_VIDEO => $this->markVideoFailure($processingLog, $message),
            self::PROFILE_VIDEO_AUTO_TRIM => $this->markVideoAutoTrimFailure($processingLog, $message),
            self::PROFILE_LIVESTREAM => $this->markLivestreamFailure($processingLog, $exception, $message),
            default => null,
        };
    }

    private function markAudioFailure(MediaProcessingLog $processingLog, string $message): void
    {
        $processingLog->update([
            'status' => ProcessingStatus::Failed,
            'error_message' => "Audio processing failed: {$message}",
            'dedup_key' => null,
        ]);
    }

    private function markVideoFailure(MediaProcessingLog $processingLog, string $message): void
    {
        $processingLog->update([
            'status' => ProcessingStatus::Failed,
            'error_message' => "Video processing failed: {$message}",
            'dedup_key' => null,
        ]);
    }

    private function markVideoAutoTrimFailure(MediaProcessingLog $processingLog, string $message): void
    {
        $this->processingRunTransitions->markAsFailed(
            $processingLog,
            "Video auto-trim processing failed: {$message}"
        );

        $tempFiles = $this->segmentationTempFiles($processingLog);

        if ($tempFiles !== []) {
            $this->storageService->cleanupTemporaryFiles($tempFiles);
        }
    }

    private function markLivestreamFailure(MediaProcessingLog $processingLog, \Throwable $exception, string $message): void
    {
        $this->processingRunTransitions->markAsFailed($processingLog, $message);
        $tempFiles = $this->segmentationTempFiles($processingLog);

        if ($tempFiles !== []) {
            $this->storageService->cleanupTemporaryFiles($tempFiles);
        }

        if (app(ProcessingNotificationRouter::class)->suppressIfHistoric(
            $processingLog,
            'failure',
            'error',
            [
                'stage' => $processingLog->current_step,
                'message' => $message,
                'exception_class' => $exception::class,
                'exception_fingerprint' => hash('sha256', $exception->getMessage()),
            ],
        )) {
            return;
        }

        if (! config('media-processing.email.send_failure_notifications')) {
            return;
        }

        try {
            Mail::to(config('media-processing.email.admin_email'))
                ->queue(new LivestreamProcessingFailed($processingLog->processing_id, $exception));
        } catch (\Throwable $mailException) {
            Log::warning('Failed to queue livestream processing failure email, continuing', $this->sanitizeArrayForLog([
                'processing_id' => $processingLog->processing_id,
                'original_error' => $exception->getMessage(),
                'email_error' => $mailException->getMessage(),
            ]));
        }
    }

    private function safeMessage(\Throwable $exception, string $profile): string
    {
        if ($exception instanceof ProvidesSafeMessage) {
            return $exception->getSafeMessage();
        }

        return match ($profile) {
            self::PROFILE_AUDIO => 'An internal error occurred during audio processing.',
            self::PROFILE_VIDEO => 'An internal error occurred during video processing.',
            self::PROFILE_VIDEO_AUTO_TRIM => 'An internal error occurred during sermon video auto-trim processing.',
            self::PROFILE_LIVESTREAM => 'An internal error occurred during livestream processing.',
            default => 'An internal error occurred during processing.',
        };
    }

    /**
     * @return list<string>
     */
    private function segmentationTempFiles(MediaProcessingLog $processingLog): array
    {
        return $processingLog->temporaryFilePaths();
    }
}
