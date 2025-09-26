<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\ProcessingStatusContract;
use App\Data\StandardProcessingResponse;
use App\Http\Requests\AutomatedSermonUploadRequest;
use App\Http\Requests\SermonVideoUploadRequest;
use App\Services\ProcessingLogService;
use App\Services\ProcessingRouter;
use App\Services\SermonProcessingLogger;
use App\Services\SermonProcessingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AutomatedSermonController extends Controller implements ProcessingStatusContract
{
    public function __construct(
        private readonly ProcessingRouter $processingRouter,
        private readonly SermonProcessingService $sermonProcessingService,
        private readonly ProcessingLogService $processingLogService
    ) {}

    /**
     * Upload and process a sermon audio file automatically
     */
    public function upload(AutomatedSermonUploadRequest $request): JsonResponse
    {
        try {
            Log::info('Automated sermon upload initiated', [
                'user_id' => $request->user()?->id,
                'original_filename' => $request->file('file')->getClientOriginalName(),
                'file_size' => $request->file('file')?->getSize(),
                'ip_address' => $request->ip(),
            ]);

            $file = $request->file('file');

            if (! $file || ! $file->isValid()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid or corrupted file uploaded',
                    'error_code' => 'INVALID_FILE',
                ], 400);
            }

            // Process the sermon through the processing router
            $result = $this->processingRouter->routeAudio($file);

            if ($result->success) {
                Log::info('Automated sermon processing initiated successfully', [
                    'processing_id' => $result->processingId,
                    'user_id' => $request->user()?->id,
                ]);

                return response()->json($result->toArray(), 202); // 202 Accepted for async processing
            } else {
                Log::warning('Automated sermon processing failed to initiate', [
                    'error_message' => $result->message,
                    'error_code' => $result->errorCode,
                    'user_id' => $request->user()?->id,
                ]);

                return response()->json($result->toArray(), 422); // 422 Unprocessable Entity
            }
        } catch (\Exception $e) {
            Log::error('Unexpected error during automated sermon upload', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'original_filename' => $request->file('file')->getClientOriginalName(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred during upload processing',
                'error_code' => 'INTERNAL_ERROR',
            ], 500);
        }
    }

    /**
     * Upload and process a sermon video file automatically
     */
    public function uploadVideo(SermonVideoUploadRequest $request): JsonResponse
    {
        try {
            Log::info('Direct sermon video upload initiated', [
                'user_id' => $request->user()?->id,
                'original_filename' => $request->file('file')->getClientOriginalName(),
                'file_size' => $request->file('file')?->getSize(),
                'ip_address' => $request->ip(),
            ]);

            $file = $request->file('file');

            if (! $file || ! $file->isValid()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid or corrupted video file uploaded',
                    'error_code' => 'INVALID_FILE',
                ], 400);
            }

            // Process the video through the processing router
            $result = $this->processingRouter->routeSermonVideo($file);

            if ($result->success) {
                Log::info('Direct sermon video processing initiated successfully', [
                    'processing_id' => $result->processingId,
                    'user_id' => $request->user()?->id,
                ]);

                return response()->json($result->toArray(), 202); // 202 Accepted for async processing
            } else {
                Log::warning('Direct sermon video processing failed to initiate', [
                    'error_message' => $result->message,
                    'error_code' => $result->errorCode,
                    'user_id' => $request->user()?->id,
                ]);

                return response()->json($result->toArray(), 422); // 422 Unprocessable Entity
            }
        } catch (\Exception $e) {
            Log::error('Unexpected error during direct sermon video upload', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'original_filename' => $request->file('file')->getClientOriginalName(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred during video processing',
                'error_code' => 'INTERNAL_ERROR',
            ], 500);
        }
    }

    /**
     * Upload and process a livestream video file automatically
     */
    public function uploadLivestream(Request $request): JsonResponse
    {
        // Use same validation pattern as existing methods
        $request->validate([
            'file' => 'required|file|mimes:mp4,mov,avi,mkv|max:2097152', // 2GB limit
        ]);

        try {
            Log::info('Livestream upload initiated', [
                'user_id' => $request->user()?->id,
                'original_filename' => $request->file('file')->getClientOriginalName(),
                'file_size' => $request->file('file')?->getSize(),
                'ip_address' => $request->ip(),
            ]);

            $file = $request->file('file');

            if (! $file || ! $file->isValid()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid or corrupted livestream file uploaded',
                    'error_code' => 'INVALID_FILE',
                ], 400);
            }

            // Process the livestream through the processing router
            $result = $this->processingRouter->routeLivestreamVideo($file);

            if ($result->success) {
                Log::info('Livestream processing initiated successfully', [
                    'processing_id' => $result->processingId,
                    'user_id' => $request->user()?->id,
                ]);

                return response()->json($result->toArray(), 202); // 202 Accepted for async processing
            } else {
                Log::warning('Livestream processing failed to initiate', [
                    'error_message' => $result->message,
                    'error_code' => $result->errorCode,
                    'user_id' => $request->user()?->id,
                ]);

                return response()->json($result->toArray(), 422); // 422 Unprocessable Entity
            }
        } catch (\Exception $e) {
            Log::error('Unexpected error during livestream upload', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'original_filename' => $request->file('file')->getClientOriginalName(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred during livestream processing',
                'error_code' => 'INTERNAL_ERROR',
            ], 500);
        }
    }

    /**
     * Get the processing status for a given processing ID
     */
    public function status(Request $request, string $processingId): JsonResponse
    {
        try {
            // Validate processing ID format (UUID)
            if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $processingId)) {
                return response()->json([
                    'found' => false,
                    'message' => 'Invalid processing ID format',
                ], 400);
            }

            if (! app()->runningUnitTests()) {
                Log::debug('Processing status requested', [
                    'processing_id' => $processingId,
                    'user_id' => $request->user()?->id,
                    'ip_address' => $request->ip(),
                    'include_logs' => $request->boolean('include_logs'),
                    'log_limit' => $request->integer('log_limit', 20),
                ]);
            }

            // Check if logs should be included
            $includeLogs = $request->boolean('include_logs');
            $logLimit = $request->integer('log_limit', 20);

            $standardResponse = $includeLogs
                ? $this->getProcessingStatusWithLogs($processingId, true, $logLimit)
                : $this->getProcessingStatus($processingId);

            if (! $standardResponse->found) {
                return response()->json($standardResponse->toArray(), 404);
            }

            return response()->json($standardResponse->toArray(), 200);
        } catch (\Exception $e) {
            Log::error('Error retrieving processing status', [
                'processing_id' => $processingId,
                'error' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'found' => false,
                'message' => 'An error occurred while retrieving processing status',
            ], 500);
        }
    }

    /**
     * Retry failed processing for a given processing ID
     */
    public function retry(Request $request, string $processingId): JsonResponse
    {
        try {
            // Validate processing ID format (UUID)
            if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $processingId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid processing ID format',
                    'error_code' => 'INVALID_PROCESSING_ID',
                ], 400);
            }

            Log::info('Processing retry requested', [
                'processing_id' => $processingId,
                'user_id' => $request->user()?->id,
                'ip_address' => $request->ip(),
            ]);

            $result = $this->sermonProcessingService->retryProcessing($processingId);

            if ($result->success) {
                Log::info('Processing retry initiated successfully', [
                    'processing_id' => $processingId,
                    'user_id' => $request->user()?->id,
                ]);

                return response()->json($result->toArray(), 202); // 202 Accepted for async processing
            } else {
                Log::warning('Processing retry failed', [
                    'processing_id' => $processingId,
                    'error_message' => $result->message,
                    'error_code' => $result->errorCode,
                    'user_id' => $request->user()?->id,
                ]);

                return response()->json($result->toArray(), 422); // 422 Unprocessable Entity
            }
        } catch (\Exception $e) {
            Log::error('Unexpected error during processing retry', [
                'processing_id' => $processingId,
                'error' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred during retry processing',
                'error_code' => 'INTERNAL_ERROR',
            ], 500);
        }
    }

    /**
     * Get failed processing logs for manual review
     */
    public function failed(Request $request): JsonResponse
    {
        try {
            $limit = $request->query('limit', 50);
            $limit = min(max((int) $limit, 1), 100); // Ensure limit is between 1 and 100

            Log::debug('Failed processing logs requested', [
                'limit' => $limit,
                'user_id' => $request->user()?->id,
                'ip_address' => $request->ip(),
            ]);

            $failedLogs = $this->sermonProcessingService->getFailedProcessingLogs($limit);

            return response()->json([
                'success' => true,
                'data' => $failedLogs,
                'count' => count($failedLogs),
                'limit' => $limit,
                'timestamp' => now()->toISOString(),
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error retrieving failed processing logs', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while retrieving failed processing logs',
            ], 500);
        }
    }

    /**
     * Apply graceful degradation to a failed processing
     */
    public function gracefulDegradation(Request $request, string $processingId): JsonResponse
    {
        try {
            // Validate processing ID format (UUID)
            if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $processingId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid processing ID format',
                    'error_code' => 'INVALID_PROCESSING_ID',
                ], 400);
            }

            Log::info('Graceful degradation requested', [
                'processing_id' => $processingId,
                'user_id' => $request->user()?->id,
                'ip_address' => $request->ip(),
            ]);

            $result = $this->sermonProcessingService->applyGracefulDegradation($processingId);

            if ($result->success) {
                Log::info('Graceful degradation applied successfully', [
                    'processing_id' => $processingId,
                    'user_id' => $request->user()?->id,
                ]);

                return response()->json($result->toArray(), 200);
            } else {
                Log::warning('Graceful degradation failed', [
                    'processing_id' => $processingId,
                    'error_message' => $result->message,
                    'error_code' => $result->errorCode,
                    'user_id' => $request->user()?->id,
                ]);

                return response()->json($result->toArray(), 422); // 422 Unprocessable Entity
            }
        } catch (\Exception $e) {
            Log::error('Unexpected error during graceful degradation', [
                'processing_id' => $processingId,
                'error' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred during graceful degradation',
                'error_code' => 'INTERNAL_ERROR',
            ], 500);
        }
    }

    /**
     * Get system health information
     */
    public function health(Request $request): JsonResponse
    {
        try {
            Log::debug('System health check requested', [
                'user_id' => $request->user()?->id,
                'ip_address' => $request->ip(),
            ]);

            $health = $this->sermonProcessingService->getSystemHealth();

            $statusCode = match ($health['overall_status']) {
                'healthy' => 200,
                'degraded' => 200, // Still return 200 but with degraded status
                'error' => 503,    // Service Unavailable
                default => 200,
            };

            return response()->json($health, $statusCode);
        } catch (\Exception $e) {
            Log::error('Error during system health check', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'overall_status' => 'error',
                'message' => 'Health check failed: '.$e->getMessage(),
                'timestamp' => now()->toISOString(),
            ], 503);
        }
    }

    /**
     * Get the current processing step for a given processing ID
     */
    private function getCurrentStep(string $processingId): string
    {
        // First check if we have detailed processing steps
        $latestStep = \App\Models\SermonProcessingStep::where('processing_id', $processingId)
            ->whereIn('step', [
                'analyzing_audio', 'segmenting', 'extracting',
                'transcribing', 'analyzing', 'creating',
            ])
            ->whereNull('completed_at')
            ->latest()
            ->first();

        if ($latestStep) {
            return $latestStep->step;
        }

        // Fallback to processing log current step
        $processingLog = \App\Models\SermonProcessingLog::where('processing_id', $processingId)->first();

        if ($processingLog && $processingLog->current_step) {
            return $processingLog->current_step;
        }

        // Default based on status
        return match ($processingLog?->status->value) {
            'pending' => 'pending',
            'processing' => 'processing',
            'completed' => 'completed',
            'failed' => 'failed',
            default => 'pending',
        };
    }

    /**
     * Get the progress percentage for a given processing ID
     */
    private function getProgressPercentage(string $processingId): int
    {
        // First try to calculate based on processing steps if available
        $completedSteps = \App\Models\SermonProcessingStep::where('processing_id', $processingId)
            ->whereNotNull('completed_at')
            ->count();

        $totalSteps = \App\Models\SermonProcessingStep::where('processing_id', $processingId)
            ->count();

        if ($totalSteps > 0) {
            return (int) min(100, round(($completedSteps / $totalSteps) * 100));
        }

        // Fallback to status-based progress estimation for basic processing
        $processingLog = \App\Models\SermonProcessingLog::where('processing_id', $processingId)->first();

        if (! $processingLog) {
            return 0;
        }

        return match ($processingLog->status->value) {
            'pending' => 5,
            'processing' => match ($processingLog->current_step) {
                'generating_rms_log' => 15,
                'analyzing_segments' => 30,
                'segment_analysis_complete' => 45,
                'processing_sermon_segment' => 55,
                'processing_video' => 60,
                'extracting_audio' => 70,
                'processing_audio' => 75,
                'transcribing_audio' => 80,
                'transcribing' => 85,
                'analyzing' => 90,
                'creating' => 95,
                default => 10,
            },
            'completed' => 100,
            'failed' => 0,
        };
    }

    /**
     * Get estimated completion time for a given processing ID
     */
    private function getEstimatedCompletion(string $processingId): ?string
    {
        // Simple estimation based on average processing times
        // This could be enhanced with historical data
        $currentStep = $this->getCurrentStep($processingId);

        $estimations = [
            'analyzing_audio' => '3-5 minutes',
            'segmenting' => '2-3 minutes',
            'extracting' => '1-2 minutes',
            'transcribing' => '2-4 minutes',
            'analyzing' => '1-2 minutes',
            'creating' => '30 seconds',
        ];

        return $estimations[$currentStep] ?? null;
    }

    /**
     * Cancel processing for a given processing ID
     */
    public function cancel(Request $request, string $processingId): JsonResponse
    {
        try {
            // Validate processing ID format
            if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $processingId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid processing ID format',
                ], 400);
            }

            Log::info('Processing cancellation requested', [
                'processing_id' => $processingId,
                'user_id' => $request->user()?->id,
            ]);

            $result = $this->cancelProcessing($processingId);

            return response()->json($result, $result['success'] ? 200 : 500);

        } catch (\Exception $e) {
            Log::error('Error cancelling processing', [
                'processing_id' => $processingId,
                'error' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Cancellation failed',
            ], 500);
        }
    }

    /**
     * Get comprehensive processing statistics and monitoring data
     */
    public function statistics(Request $request): JsonResponse
    {
        try {
            $days = $request->query('days', 7);
            $days = min(max((int) $days, 1), 30); // Ensure days is between 1 and 30

            Log::debug('Processing statistics requested', [
                'days' => $days,
                'user_id' => $request->user()?->id,
                'ip_address' => $request->ip(),
            ]);

            // Get basic statistics for backward compatibility
            $basicStats = $this->sermonProcessingService->getProcessingStatistics();

            // Get comprehensive statistics using the new logger
            $logger = app(SermonProcessingLogger::class);
            $comprehensiveStats = $logger->generateProcessingStatistics($days);

            // Add current system health
            $health = $this->sermonProcessingService->getSystemHealth();

            // Add performance metrics
            $performanceMetrics = [
                'memory_usage' => memory_get_usage(true),
                'peak_memory' => memory_get_peak_usage(true),
                'uptime' => defined('LARAVEL_START') ? microtime(true) - LARAVEL_START : null,
            ];

            // Merge basic stats with comprehensive data for backward compatibility
            $responseData = array_merge($basicStats, [
                'comprehensive_statistics' => $comprehensiveStats,
                'health' => $health,
                'performance' => $performanceMetrics,
            ]);

            return response()->json([
                'success' => true,
                'data' => $responseData,
                'timestamp' => now()->toISOString(),
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error retrieving processing statistics', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while retrieving processing statistics',
            ], 500);
        }
    }

    /**
     * Get processing status for a given processing ID (Contract implementation)
     */
    public function getProcessingStatus(string $processingId): StandardProcessingResponse
    {
        try {
            $statusResult = $this->sermonProcessingService->getProcessingStatus($processingId);

            if (! $statusResult->found) {
                return StandardProcessingResponse::notFound();
            }

            // Get thumbnail information if sermon exists
            $thumbnailData = [];
            if ($statusResult->sermonId) {
                $sermon = \App\Models\Sermon::find($statusResult->sermonId);
                if ($sermon) {
                    $thumbnailData = [
                        'thumbnail_url' => $sermon->thumbnail_url,
                        'thumbnail_generated' => $sermon->hasThumbnail(),
                        'thumbnail_generated_at' => $sermon->thumbnail_generated_at?->toISOString(),
                    ];
                }
            }

            // Create standardized response with enhanced information
            return StandardProcessingResponse::found(
                processingId: $statusResult->processingId,
                status: $statusResult->status->value,
                currentStep: $this->getCurrentStep($processingId),
                progressPercentage: $this->getProgressPercentage($processingId),
                errorMessage: $statusResult->errorMessage,
                sermonId: $statusResult->sermonId,
                sermonUrl: $statusResult->sermonSlug ? "/christ/sermons/{$statusResult->sermonSlug}" : null,
                startedAt: $statusResult->createdAt,
                updatedAt: $statusResult->updatedAt,
                estimatedCompletion: $this->getEstimatedCompletion($processingId),
                additionalData: $thumbnailData
            );
        } catch (\Exception $e) {
            Log::error('Error retrieving processing status', [
                'processing_id' => $processingId,
                'error' => $e->getMessage(),
            ]);

            return StandardProcessingResponse::error('Failed to retrieve processing status: '.$e->getMessage());
        }
    }

    /**
     * Cancel processing for a given processing ID (Contract implementation)
     */
    public function cancelProcessing(string $processingId): array
    {
        try {
            // Validate processing ID format
            if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $processingId)) {
                return [
                    'success' => false,
                    'message' => 'Invalid processing ID format',
                ];
            }

            Log::info('Processing cancellation requested', [
                'processing_id' => $processingId,
            ]);

            // Mark all incomplete steps as cancelled in database
            \App\Models\SermonProcessingStep::where('processing_id', $processingId)
                ->whereNull('completed_at')
                ->update([
                    'status' => 'cancelled',
                    'completed_at' => now(),
                    'message' => 'Cancelled by user',
                ]);

            // Also update the main processing log
            \App\Models\SermonProcessingLog::where('processing_id', $processingId)
                ->update([
                    'status' => \App\Enums\ProcessingStatus::FAILED,
                    'current_step' => 'cancelled',
                    'error_message' => 'Processing cancelled by user',
                ]);

            return [
                'success' => true,
                'message' => 'Processing has been cancelled',
            ];

        } catch (\Exception $e) {
            Log::error('Error cancelling processing', [
                'processing_id' => $processingId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Cancellation failed',
            ];
        }
    }

    /**
     * Get processing status with optional logs included (Contract implementation)
     */
    public function getProcessingStatusWithLogs(
        string $processingId,
        bool $includeLogs = false,
        int $logLimit = 20
    ): StandardProcessingResponse {
        try {
            $baseStatus = $this->getProcessingStatus($processingId);

            if (! $baseStatus->found || ! $includeLogs) {
                return $baseStatus;
            }

            $logs = $this->processingLogService->getProcessingLogs($processingId, $logLimit);
            $metrics = $this->processingLogService->getPerformanceMetrics($processingId);

            return StandardProcessingResponse::withLogs(
                processingId: $baseStatus->processingId,
                status: $baseStatus->status,
                currentStep: $baseStatus->currentStep,
                progressPercentage: $baseStatus->progressPercentage,
                errorMessage: $baseStatus->errorMessage,
                sermonId: $baseStatus->sermonId,
                sermonUrl: $baseStatus->sermonUrl,
                startedAt: $baseStatus->startedAt,
                updatedAt: $baseStatus->updatedAt,
                estimatedCompletion: $baseStatus->estimatedCompletion,
                additionalData: $baseStatus->additionalData,
                logs: $logs,
                metrics: $metrics
            );
        } catch (\Exception $e) {
            Log::error('Error retrieving processing status with logs', [
                'processing_id' => $processingId,
                'error' => $e->getMessage(),
            ]);

            return StandardProcessingResponse::error('Failed to retrieve processing status with logs: '.$e->getMessage());
        }
    }

    /**
     * Determine if this controller can handle the given processing ID
     */
    public function canHandle(string $processingId): bool
    {
        // Check if processing ID exists in sermon processing logs
        return \App\Models\SermonProcessingLog::where('processing_id', $processingId)->exists();
    }
}
