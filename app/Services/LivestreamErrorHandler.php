<?php

namespace App\Services;

use App\Models\MediaProcessingLog;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class LivestreamErrorHandler
{
    private LivestreamProcessingLogger $logger;

    public function __construct(LivestreamProcessingLogger $logger)
    {
        $this->logger = $logger;
    }

    public function handleProcessingFailure(string $processingId, \Throwable $exception, string $step = 'unknown'): void
    {
        $this->logger->logError($processingId, $step, $exception);

        $processing = MediaProcessingLog::where('processing_id', $processingId)->first();

        if ($processing) {
            $processing->update([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
            ]);
        }

        $this->sendFailureNotification($processingId, $exception, $step);
    }

    public function handlePartialFailure(string $processingId, string $step, string $message, array $context = []): void
    {
        $this->logger->logWarning($processingId, $step, $message, $context);

        $processing = MediaProcessingLog::where('processing_id', $processingId)->first();

        if ($processing && ! $processing->isFailed()) {
            $processing->update([
                'status' => \App\Enums\ProcessingStatus::COMPLETED,
                'error_message' => $message,
            ]);
        }
    }

    public function shouldRetry(\Throwable $exception, int $attemptNumber = 1): bool
    {
        $maxRetries = config('media-processing.max_retries', 3);

        if ($attemptNumber >= $maxRetries) {
            return false;
        }

        return $this->isRetryableError($exception);
    }

    public function getRetryDelay(int $attemptNumber): int
    {
        $baseDelay = config('media-processing.retry_base_delay', 60);
        $maxDelay = config('media-processing.retry_max_delay', 3600);

        $delay = $baseDelay * pow(2, $attemptNumber - 1);

        return min($delay, $maxDelay);
    }

    private function isRetryableError(\Throwable $exception): bool
    {
        $retryableExceptions = [
            \Symfony\Component\Process\Exception\ProcessTimedOutException::class,
            \Illuminate\Http\Client\ConnectionException::class,
            \Illuminate\Database\QueryException::class,
            \League\Flysystem\FilesystemException::class,
        ];

        foreach ($retryableExceptions as $retryableException) {
            if ($exception instanceof $retryableException) {
                return true;
            }
        }

        $retryableMessages = [
            'connection timed out',
            'disk full',
            'temporary failure',
            'service unavailable',
            'ffmpeg: error while loading shared libraries',
        ];

        $message = strtolower($exception->getMessage());

        foreach ($retryableMessages as $retryableMessage) {
            if (str_contains($message, $retryableMessage)) {
                return true;
            }
        }

        return false;
    }

    public function handleSegmentationFailure(string $processingId, string $reason, array $segments = []): void
    {
        $this->logger->logWarning($processingId, 'segmentation', "Segmentation issues: {$reason}", [
            'segments_found' => count($segments),
            'reason' => $reason,
        ]);

        $processing = MediaProcessingLog::where('processing_id', $processingId)->first();

        if ($processing) {
            $processing->update([
                'status' => 'failed',
                'error_message' => "Segmentation requires manual review: {$reason}",
            ]);
        }

        $this->sendManualReviewNotification($processingId, $reason, $segments);
    }

    public function handleVideoExtractionFailure(string $processingId, \Throwable $exception): void
    {
        $this->logger->logWarning($processingId, 'video_extraction', 'Video extraction failed, continuing with audio-only processing', [
            'error' => $exception->getMessage(),
        ]);

        $processing = MediaProcessingLog::where('processing_id', $processingId)->first();

        if ($processing) {
            $processing->update([
                'error_message' => 'Video extraction failed: '.$exception->getMessage(),
            ]);
        }
    }

    public function handleStorageError(string $processingId, \Throwable $exception, string $operation): bool
    {
        $this->logger->logError($processingId, 'storage', $exception);

        if ($this->isDiskSpaceError($exception)) {
            $this->handleDiskSpaceError($processingId);

            return false;
        }

        if ($this->isPermissionError($exception)) {
            $this->handlePermissionError($processingId, $operation);

            return false;
        }

        return $this->shouldRetry($exception);
    }

    private function isDiskSpaceError(\Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'no space left') ||
               str_contains($message, 'disk full') ||
               str_contains($message, 'insufficient storage');
    }

    private function isPermissionError(\Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'permission denied') ||
               str_contains($message, 'access denied') ||
               str_contains($message, 'forbidden');
    }

    private function handleDiskSpaceError(string $processingId): void
    {
        $processing = MediaProcessingLog::where('processing_id', $processingId)->first();

        if ($processing) {
            $processing->update([
                'status' => 'failed',
                'error_message' => 'Insufficient disk space for processing',
            ]);
        }

        try {
            Mail::to(config('media-processing.admin_email'))
                ->send(new \App\Mail\DiskSpaceWarning($processingId));
        } catch (Exception $e) {
            Log::warning('Failed to send disk space warning email, continuing processing', [
                'processing_id' => $processingId,
                'email_error' => $e->getMessage(),
            ]);
        }
    }

    private function handlePermissionError(string $processingId, string $operation): void
    {
        $processing = MediaProcessingLog::where('processing_id', $processingId)->first();

        if ($processing) {
            $processing->update([
                'status' => 'failed',
                'error_message' => "File permission error during {$operation}",
            ]);
        }

        try {
            Mail::to(config('media-processing.admin_email'))
                ->send(new \App\Mail\PermissionError($processingId, $operation));
        } catch (Exception $e) {
            Log::warning('Failed to send permission error email, continuing processing', [
                'processing_id' => $processingId,
                'operation' => $operation,
                'email_error' => $e->getMessage(),
            ]);
        }
    }

    private function sendFailureNotification(string $processingId, \Throwable $exception, string $step): void
    {
        try {
            Mail::to(config('media-processing.admin_email'))
                ->send(new \App\Mail\LivestreamProcessingFailed($processingId, $exception, $step));
        } catch (Exception $e) {
            Log::warning('Failed to send failure notification email, continuing processing', [
                'processing_id' => $processingId,
                'original_error' => $exception->getMessage(),
                'email_error' => $e->getMessage(),
            ]);
        }
    }

    private function sendManualReviewNotification(string $processingId, string $reason, array $segments): void
    {
        try {
            Mail::to(config('media-processing.admin_email'))
                ->send(new \App\Mail\ManualReviewRequired($processingId, $reason, $segments));
        } catch (Exception $e) {
            Log::warning('Failed to send manual review notification email, continuing processing', [
                'processing_id' => $processingId,
                'reason' => $reason,
                'email_error' => $e->getMessage(),
            ]);
        }
    }

    public function validateFileFormat(string $filePath): array
    {
        // Get supported formats from the unified media-processing config
        $livestreamExtensions = config('media-processing.types.livestream.allowed_extensions', []);
        $videoExtensions = config('media-processing.types.video.allowed_extensions', []);
        $supportedFormats = array_merge($livestreamExtensions, $videoExtensions);

        // Fallback if config is missing
        if (empty($supportedFormats)) {
            $supportedFormats = ['mp4', 'mov', 'avi', 'mkv', 'webm'];
            Log::warning('LivestreamErrorHandler using fallback supported formats', [
                'fallback_formats' => $supportedFormats,
                'file_path' => $filePath,
            ]);
        }

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $errors = [];

        if (! in_array($extension, $supportedFormats)) {
            $errors[] = "Unsupported file format: {$extension}. Supported formats: ".implode(', ', $supportedFormats);
        }

        if (! file_exists($filePath)) {
            $errors[] = "File does not exist: {$filePath}";
        }

        // Use livestream-specific file size limit (2GB)
        $maxSize = config('media-processing.types.livestream.max_file_size', 2147483648); // 2GB default
        if (file_exists($filePath) && filesize($filePath) > $maxSize) {
            $errors[] = 'File size exceeds maximum allowed size of '.$this->formatBytes($maxSize);
        }

        return $errors;
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, 2).' '.$units[$pow];
    }

    public function checkSystemRequirements(): array
    {
        $errors = [];

        $ffmpegPath = config('media-processing.ffmpeg.ffmpeg_path', '/usr/bin/ffmpeg');
        if (! file_exists($ffmpegPath) || ! is_executable($ffmpegPath)) {
            $errors[] = "FFmpeg not found or not executable at: {$ffmpegPath}";
        }

        $ffprobePath = config('media-processing.ffmpeg.ffprobe_path', '/usr/bin/ffprobe');
        if (! file_exists($ffprobePath) || ! is_executable($ffprobePath)) {
            $errors[] = "FFprobe not found or not executable at: {$ffprobePath}";
        }

        $tempDir = storage_path('app/temp');
        if (! is_dir($tempDir) || ! is_writable($tempDir)) {
            $errors[] = "Temporary directory not writable: {$tempDir}";
        }

        $livestreamDir = storage_path('app/livestreams');
        if (! is_dir($livestreamDir) || ! is_writable($livestreamDir)) {
            $errors[] = "Livestream directory not writable: {$livestreamDir}";
        }

        return $errors;
    }

    public function gracefulDegradation(string $processingId, string $reason, ?callable $fallbackAction = null): void
    {
        $this->logger->logWarning($processingId, 'degradation', "Graceful degradation triggered: {$reason}");

        if ($fallbackAction) {
            try {
                $fallbackAction();
                $this->logger->logProcessingStep($processingId, 'fallback_action_completed');
            } catch (Exception $e) {
                $this->logger->logError($processingId, 'fallback_action', $e);
            }
        }
    }
}
