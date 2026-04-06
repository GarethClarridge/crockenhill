<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\ProvidesSafeMessage;
use App\Enums\ProcessingStatus;
use App\Mail\LivestreamProcessingFailed;
use App\Models\MediaProcessingLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ProcessingRunFailureHandler
{
    public const PROFILE_AUDIO = 'audio';

    public const PROFILE_VIDEO = 'video';

    public const PROFILE_VIDEO_AUTO_TRIM = 'video_auto_trim';

    public const PROFILE_LIVESTREAM = 'livestream';

    public function __construct(
        private readonly VideoStorageService $storageService,
        private readonly MediaProcessingRunTransitionService $processingRunTransitions,
    ) {}

    public function handle(string $processingId, \Throwable $exception, string $profile): void
    {
        Log::error('Processing run failure', [
            'processing_id' => $processingId,
            'profile' => $profile,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        $processingLog = MediaProcessingLog::query()
            ->where('processing_id', $processingId)
            ->first();

        if (! $processingLog instanceof MediaProcessingLog) {
            return;
        }

        $processingLog = $processingLog->fresh() ?? $processingLog;

        if ($processingLog->isCancelled()) {
            Log::info('Skipping failure handling for cancelled processing run', [
                'processing_id' => $processingId,
                'profile' => $profile,
            ]);

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
        ]);
    }

    private function markVideoFailure(MediaProcessingLog $processingLog, string $message): void
    {
        $processingLog->update([
            'status' => ProcessingStatus::Failed,
            'error_message' => "Video processing failed: {$message}",
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

        try {
            Mail::to(config('media-processing.email.admin_email'))
                ->queue(new LivestreamProcessingFailed($processingLog->processing_id, $exception));
        } catch (\Throwable $mailException) {
            Log::warning('Failed to queue livestream processing failure email, continuing', [
                'processing_id' => $processingLog->processing_id,
                'original_error' => $exception->getMessage(),
                'email_error' => $mailException->getMessage(),
            ]);
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
        $tempFiles = [];

        if (is_string($processingLog->source_file_path) && $processingLog->source_file_path !== '') {
            $tempFiles[] = $processingLog->source_file_path;
        }

        $metadata = $processingLog->processing_metadata?->toArray() ?? [];

        foreach (['extracted_segment_path', 'extracted_audio_path', 'temp_video_path'] as $key) {
            if (is_string($metadata[$key] ?? null) && $metadata[$key] !== '') {
                $tempFiles[] = $metadata[$key];
            }
        }

        return array_values(array_unique($tempFiles));
    }
}
