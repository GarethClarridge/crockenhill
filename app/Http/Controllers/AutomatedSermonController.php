<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\AutomatedSermonUploadRequest;
use App\Http\Requests\SermonVideoUploadRequest;
use App\Services\SermonProcessingLogger;
use App\Services\SermonProcessingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AutomatedSermonController extends Controller
{
  public function __construct(
    private readonly SermonProcessingService $sermonProcessingService
  ) {}

  /**
   * Upload and process a sermon audio file automatically
   */
  public function upload(AutomatedSermonUploadRequest $request): JsonResponse
  {
    try {
      Log::info('Automated sermon upload initiated', [
        'user_id' => $request->user()?->id,
        'original_filename' => $request->file('file')?->getClientOriginalName(),
        'file_size' => $request->file('file')?->getSize(),
        'ip_address' => $request->ip(),
      ]);

      $file = $request->file('file');

      if (!$file || !$file->isValid()) {
        return response()->json([
          'success' => false,
          'message' => 'Invalid or corrupted file uploaded',
          'error_code' => 'INVALID_FILE',
        ], 400);
      }

      // Process the sermon through the automated pipeline
      $result = $this->sermonProcessingService->processSermon($file);

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
        'original_filename' => $request->file('file')?->getClientOriginalName(),
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
        'original_filename' => $request->file('file')?->getClientOriginalName(),
        'file_size' => $request->file('file')?->getSize(),
        'ip_address' => $request->ip(),
      ]);

      $file = $request->file('file');

      if (!$file || !$file->isValid()) {
        return response()->json([
          'success' => false,
          'message' => 'Invalid or corrupted video file uploaded',
          'error_code' => 'INVALID_FILE',
        ], 400);
      }

      // Process the video through the direct sermon pipeline
      $result = $this->sermonProcessingService->processSermonVideo($file);

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
        'original_filename' => $request->file('file')?->getClientOriginalName(),
      ]);

      return response()->json([
        'success' => false,
        'message' => 'An unexpected error occurred during video processing',
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
      if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $processingId)) {
        return response()->json([
          'found' => false,
          'message' => 'Invalid processing ID format',
        ], 400);
      }

      Log::debug('Processing status requested', [
        'processing_id' => $processingId,
        'user_id' => $request->user()?->id,
        'ip_address' => $request->ip(),
      ]);

      $statusResult = $this->sermonProcessingService->getProcessingStatus($processingId);

      if (!$statusResult->found) {
        return response()->json($statusResult->toArray(), 404);
      }

      return response()->json($statusResult->toArray(), 200);
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
      if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $processingId)) {
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
      if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $processingId)) {
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
        'message' => 'Health check failed: ' . $e->getMessage(),
        'timestamp' => now()->toISOString(),
      ], 503);
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
}
