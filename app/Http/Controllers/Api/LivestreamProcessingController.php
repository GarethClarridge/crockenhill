<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LivestreamProcessingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class LivestreamProcessingController extends Controller
{
    public function __construct(
        private LivestreamProcessingService $livestreamProcessingService
    ) {}

    public function uploadVideo(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'video' => [
                    'required',
                    'file',
                    'mimes:mp4,mov,avi,mkv,webm',
                    'max:' . (config('livestream-processing.max_file_size') / 1024), // Convert bytes to KB for validation
                ],
                'options' => 'sometimes|array',
                'options.rms_threshold' => 'sometimes|numeric|between:-60,0',
                'options.min_section_duration' => 'sometimes|numeric|min:10',
                'options.min_sermon_duration' => 'sometimes|numeric|min:60',
            ]);

            $videoFile = $request->file('video');
            
            if (!$videoFile || !$videoFile->isValid()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid video file uploaded',
                    'errors' => ['video' => ['The uploaded video file is invalid']]
                ], 422);
            }

            $result = $this->livestreamProcessingService->startProcessing($videoFile);

            Log::info('Livestream processing initiated via API', [
                'processing_id' => $result->processingId,
                'original_filename' => $videoFile->getClientOriginalName(),
                'file_size' => $videoFile->getSize()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Livestream processing initiated',
                'processing_id' => $result->processingId,
                'status_url' => route('api.livestream.processing.status', ['processingId' => $result->processingId]),
                'estimated_completion' => now()->addMinutes($this->estimateProcessingTime($videoFile->getSize()))->toISOString(),
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            Log::error('Livestream processing upload failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => [
                    'has_file' => $request->hasFile('video'),
                    'file_size' => $request->hasFile('video') ? $request->file('video')?->getSize() : null,
                ]
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to initiate livestream processing',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    public function getStatus(string $processingId): JsonResponse
    {
        try {
            $status = $this->livestreamProcessingService->getProcessingStatus($processingId);

            return response()->json([
                'processing_id' => $status->processingId,
                'status' => $status->status,
                'current_step' => $status->currentStep,
                'progress_percentage' => $status->progressPercentage,
                'segments_identified' => count($status->stepDetails['segmentation']['segments'] ?? []),
                'sermon_processing_id' => $status->stepDetails['sermon_processing_id'] ?? null,
                'sermon_video_path' => $status->stepDetails['sermon_video_path'] ?? null,
                'segments' => array_map(function($segment) {
                    return [
                        'index' => $segment['segment_order'] ?? 0,
                        'start_time' => $segment['start_time'],
                        'end_time' => $segment['end_time'],
                        'classification' => $segment['classification'],
                        'is_sermon' => $segment['is_sermon_candidate'] ?? false,
                    ];
                }, $status->stepDetails['segmentation']['segments'] ?? [])
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get livestream processing status', [
                'processing_id' => $processingId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Processing record not found',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    public function getResult(string $processingId): JsonResponse
    {
        try {
            $result = $this->livestreamProcessingService->getProcessingResult($processingId);

            return response()->json([
                'success' => true,
                'data' => [
                    'processing_id' => $result->processingId,
                    'status' => $result->status,
                    'status_display' => $result->getStatusDisplayName(),
                    'original_filename' => $result->originalFilename,
                    'file_size' => $result->fileSize,
                    'file_size_formatted' => $result->getFileSizeFormatted(),
                    'file_format' => $result->fileFormat,
                    'duration' => $result->duration,
                    'duration_formatted' => $result->getDurationFormatted(),
                    'sermon_start_time' => $result->sermonStartTime,
                    'sermon_end_time' => $result->sermonEndTime,
                    'sermon_duration_formatted' => $result->getSermonDurationFormatted(),
                    'sermon_id' => $result->sermonId,
                    'error_message' => $result->errorMessage,
                    'processing_metadata' => $result->processingMetadata,
                    'started_at' => $result->startedAt,
                    'completed_at' => $result->completedAt,
                    'segments' => array_map(function($segment) {
                        return [
                            'start_time' => $segment->startTime,
                            'end_time' => $segment->endTime,
                            'duration' => $segment->duration,
                            'duration_formatted' => $segment->getDurationFormatted(),
                            'classification' => $segment->classification,
                            'avg_rms' => $segment->avgRms,
                            'peak_rms' => $segment->peakRms,
                            'is_sermon_candidate' => $segment->isSermonCandidate,
                            'segment_order' => $segment->segmentOrder,
                        ];
                    }, $result->segments ?? []),
                    'segments_summary' => $result->segmentsSummary,
                    'has_sermon' => $result->hasSermon(),
                    'has_segments' => $result->hasSegments(),
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get livestream processing result', [
                'processing_id' => $processingId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Processing record not found',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    public function retryProcessing(string $processingId): JsonResponse
    {
        try {
            $result = $this->livestreamProcessingService->retryProcessing($processingId);

            Log::info('Livestream processing retry initiated via API', [
                'processing_id' => $processingId
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Processing retry initiated successfully',
                'data' => [
                    'processing_id' => $result->processingId,
                    'status' => $result->status,
                    'status_url' => route('api.livestream.status', ['processingId' => $processingId]),
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to retry livestream processing', [
                'processing_id' => $processingId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retry processing',
                'error' => $e->getMessage()
            ], 400);
        }
    }

    public function cancelProcessing(string $processingId): JsonResponse
    {
        try {
            $this->livestreamProcessingService->cancelProcessing($processingId);

            Log::info('Livestream processing cancelled via API', [
                'processing_id' => $processingId
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Processing cancelled successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to cancel livestream processing', [
                'processing_id' => $processingId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel processing',
                'error' => $e->getMessage()
            ], 400);
        }
    }

    public function getProcessingSummary(): JsonResponse
    {
        try {
            $summary = $this->livestreamProcessingService->getProcessingSummary();

            return response()->json([
                'success' => true,
                'data' => $summary
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get processing summary', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to get processing summary',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function estimateProcessingTime(int $fileSizeBytes): int
    {
        $fileSizeMB = $fileSizeBytes / (1024 * 1024);
        
        if ($fileSizeMB < 100) {
            return 5;
        } elseif ($fileSizeMB < 500) {
            return 15;
        } elseif ($fileSizeMB < 1000) {
            return 30;
        } else {
            return 60;
        }
    }
}
