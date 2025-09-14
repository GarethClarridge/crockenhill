<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * ProcessingExceptionHandler - Centralized error handling for processing operations
 *
 * Part of Phase 5 refactoring to improve error handling and reduce method complexity.
 * Provides consistent error handling patterns across all processing services.
 */
class ProcessingExceptionHandler
{
    /**
     * Handle video processing exceptions with context
     */
    public function handleVideoProcessingException(\Exception $e, array $context): ProcessingResult
    {
        Log::error('Video processing failed', array_merge($context, [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]));

        return ProcessingResult::failure(
            processingId: $context['processing_id'],
            message: $this->getUserFriendlyMessage($e),
            errorCode: $this->mapExceptionToCode($e)
        );
    }

    /**
     * Handle audio processing exceptions with context
     */
    public function handleAudioProcessingException(\Exception $e, array $context): ProcessingResult
    {
        Log::error('Audio processing failed', array_merge($context, [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]));

        return ProcessingResult::failure(
            processingId: $context['processing_id'],
            message: $this->getUserFriendlyMessage($e),
            errorCode: $this->mapExceptionToCode($e)
        );
    }

    /**
     * Handle livestream processing exceptions with context
     */
    public function handleLivestreamProcessingException(\Exception $e, array $context): ProcessingResult
    {
        Log::error('Livestream processing failed', array_merge($context, [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]));

        return ProcessingResult::failure(
            processingId: $context['processing_id'],
            message: $this->getUserFriendlyMessage($e),
            errorCode: $this->mapExceptionToCode($e)
        );
    }

    /**
     * Handle job processing failures
     */
    public function handleJobFailure(\Throwable $exception, string $jobClass, array $context = []): void
    {
        Log::error('Job processing failed', array_merge($context, [
            'job_class' => $jobClass,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]));

        // Additional job-specific error handling could be added here
        // For example: retry logic, notification sending, etc.
    }

    /**
     * Convert technical exceptions to user-friendly messages
     */
    private function getUserFriendlyMessage(\Exception $e): string
    {
        // Map specific exceptions to user-friendly messages
        $exceptionMap = [
            'FFMpeg\Exception\ExecutableNotFoundException' => 'Video processing tools are not available on the server.',
            'FFMpeg\Exception\RuntimeException' => 'Video processing failed due to file format or corruption issues.',
            'InvalidArgumentException' => 'The uploaded file does not meet the required format or size specifications.',
            'OutOfMemoryException' => 'The file is too large for processing. Please try with a smaller file.',
            'TimeoutException' => 'Processing took too long and was cancelled. Please try again later.',
        ];

        $exceptionClass = get_class($e);

        if (isset($exceptionMap[$exceptionClass])) {
            return $exceptionMap[$exceptionClass];
        }

        // Check for specific error message patterns
        $message = $e->getMessage();

        if (str_contains($message, 'disk space')) {
            return 'Insufficient storage space to process the file. Please contact support.';
        }

        if (str_contains($message, 'permission')) {
            return 'File access permission error. Please contact support.';
        }

        if (str_contains($message, 'timeout')) {
            return 'Processing timed out. Please try again with a smaller file or contact support.';
        }

        // Default fallback message
        return 'An unexpected error occurred during processing. Please try again or contact support if the problem persists.';
    }

    /**
     * Map exceptions to specific error codes for API responses
     */
    private function mapExceptionToCode(\Exception $e): string
    {
        $exceptionClass = get_class($e);

        $codeMap = [
            'FFMpeg\Exception\ExecutableNotFoundException' => 'FFMPEG_NOT_AVAILABLE',
            'FFMpeg\Exception\RuntimeException' => 'VIDEO_PROCESSING_FAILED',
            'InvalidArgumentException' => 'INVALID_FILE_FORMAT',
            'OutOfMemoryException' => 'FILE_TOO_LARGE',
            'TimeoutException' => 'PROCESSING_TIMEOUT',
        ];

        if (isset($codeMap[$exceptionClass])) {
            return $codeMap[$exceptionClass];
        }

        // Check for specific error message patterns
        $message = $e->getMessage();

        if (str_contains($message, 'disk space')) {
            return 'INSUFFICIENT_STORAGE';
        }

        if (str_contains($message, 'permission')) {
            return 'FILE_PERMISSION_ERROR';
        }

        if (str_contains($message, 'timeout')) {
            return 'PROCESSING_TIMEOUT';
        }

        return 'UNKNOWN_ERROR';
    }

    /**
     * Log processing metrics for monitoring
     */
    public function logProcessingMetrics(string $processingType, array $metrics): void
    {
        Log::info('Processing metrics', array_merge($metrics, [
            'processing_type' => $processingType,
            'timestamp' => now()->toISOString(),
        ]));
    }
}