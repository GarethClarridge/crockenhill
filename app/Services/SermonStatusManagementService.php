<?php

namespace App\Services;

use App\Data\StandardProcessingResponse;
use App\Enums\ProcessingStatus;
use App\Models\MediaProcessingLog;
use Illuminate\Support\Facades\Log;

class SermonStatusManagementService
{
    public function __construct(
        private SermonValidationService $validationService
    ) {}

    /**
     * Get the current processing status for a given processing ID
     */
    public function getProcessingStatus(string $processingId): StandardProcessingResponse
    {
        try {
            $processingLog = MediaProcessingLog::where('processing_id', $processingId)->first();

            if (! $processingLog) {
                return StandardProcessingResponse::notFound();
            }

            return StandardProcessingResponse::fromProcessingLog($processingLog);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve processing status', [
                'processing_id' => $processingId,
                'error' => $e->getMessage(),
            ]);

            return StandardProcessingResponse::error(
                errorMessage: 'Failed to retrieve processing status: '.$e->getMessage()
            );
        }
    }

    /**
     * Get processing statistics and recent activity
     */
    public function getProcessingStatistics(): array
    {
        try {
            $stats = [
                'total_processed' => MediaProcessingLog::count(),
                'completed' => MediaProcessingLog::completed()->count(),
                'failed' => MediaProcessingLog::failed()->count(),
                'in_progress' => MediaProcessingLog::processing()->count(),
                'pending' => MediaProcessingLog::pending()->count(),
                'recent_activity' => MediaProcessingLog::recent()
                    ->with('sermon')
                    ->orderBy('created_at', 'desc')
                    ->limit(10)
                    ->get()
                    ->map(function ($log) {
                        return [
                            'processing_id' => $log->processing_id,
                            'status' => $log->status->label(),
                            'current_step' => $log->current_step,
                            'sermon_title' => $log->sermon?->title,
                            'created_at' => $log->created_at->diffForHumans(),
                            'has_errors' => ! empty($log->error_message),
                        ];
                    }),
            ];

            return $stats;
        } catch (\Exception $e) {
            Log::error('Failed to retrieve processing statistics', [
                'error' => $e->getMessage(),
            ]);

            return [
                'error' => 'Failed to retrieve statistics',
                'total_processed' => 0,
                'completed' => 0,
                'failed' => 0,
                'in_progress' => 0,
                'pending' => 0,
                'recent_activity' => [],
            ];
        }
    }

    /**
     * Get failed processing logs that may need manual review
     */
    public function getFailedProcessingLogs(int $limit = 50): array
    {
        try {
            $failedLogs = MediaProcessingLog::failed()
                ->with('sermon')
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get();

            return $failedLogs->map(function ($log) {
                return [
                    'processing_id' => $log->processing_id,
                    'original_filename' => $log->original_filename,
                    'current_step' => $log->current_step,
                    'error_message' => $log->error_message,
                    'created_at' => $log->created_at->toISOString(),
                    'updated_at' => $log->updated_at->toISOString(),
                    'sermon' => $log->sermon ? [
                        'id' => $log->sermon->id,
                        'title' => $log->sermon->title,
                        'slug' => $log->sermon->slug,
                    ] : null,
                    'can_retry' => $this->validationService->canRetryProcessing($log),
                    'requires_manual_review' => $this->validationService->requiresManualReview($log),
                ];
            })->toArray();
        } catch (\Exception $e) {
            Log::error('Failed to retrieve failed processing logs', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Mark processing for manual review
     */
    public function markForManualReview(string $processingId, string $reviewNote = ''): bool
    {
        try {
            $processingLog = MediaProcessingLog::where('processing_id', $processingId)->first();

            if (! $processingLog) {
                Log::warning('Processing log not found for manual review marking', [
                    'processing_id' => $processingId,
                ]);

                return false;
            }

            $errorMessage = $reviewNote ? "Manual Review Note: {$reviewNote}" : 'Marked for manual review';

            $processingLog->update([
                'status' => ProcessingStatus::FAILED,
                'current_step' => 'manual_review_required',
                'error_message' => $errorMessage,
            ]);

            Log::info('Processing marked for manual review', [
                'processing_id' => $processingId,
                'review_note' => $reviewNote,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to mark processing for manual review', [
                'processing_id' => $processingId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
