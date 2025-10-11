<?php

namespace App\Services;

use App\Data\StandardProcessingResponse;
use App\Enums\ProcessingStatus;
use App\Models\MediaProcessingLog;
use Illuminate\Support\Facades\Log;

class SermonStatusManagementService
{
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
                    'can_retry' => $this->canRetryProcessing($log),
                    'requires_manual_review' => $this->requiresManualReview($log),
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

    /**
     * Get detailed processing logs for troubleshooting
     */
    public function getDetailedProcessingLogs(string $processingId): array
    {
        try {
            $processingLog = MediaProcessingLog::where('processing_id', $processingId)
                ->with('sermon')
                ->first();

            if (! $processingLog) {
                return [
                    'found' => false,
                    'message' => 'Processing log not found',
                ];
            }

            // Get related log entries from Laravel logs
            $logEntries = $this->extractLogEntries($processingId);

            return [
                'found' => true,
                'processing_log' => [
                    'processing_id' => $processingLog->processing_id,
                    'original_filename' => $processingLog->original_filename,
                    'status' => $processingLog->status->value,
                    'status_label' => $processingLog->status->label(),
                    'current_step' => $processingLog->current_step,
                    'error_message' => $processingLog->error_message,
                    'created_at' => $processingLog->created_at->toISOString(),
                    'updated_at' => $processingLog->updated_at->toISOString(),
                    'duration' => $processingLog->created_at->diffForHumans($processingLog->updated_at),
                ],
                'sermon' => $processingLog->sermon instanceof \App\Models\Sermon ? [
                    'id' => $processingLog->sermon->id,
                    'title' => $processingLog->sermon->title,
                    'slug' => $processingLog->sermon->slug,
                    'date' => $processingLog->sermon->date->toDateString(),
                    'service' => $processingLog->sermon->service?->value,
                    'preacher' => $processingLog->sermon->preacher,
                    'series' => $processingLog->sermon->series,
                    'reference' => $processingLog->sermon->reference,
                    'has_transcript' => $processingLog->sermon->hasTranscript(),
                ] : null,
                'log_entries' => $logEntries,
                'troubleshooting' => $this->generateTroubleshootingInfo($processingLog),
            ];
        } catch (\Exception $e) {
            Log::error('Failed to retrieve detailed processing logs', [
                'processing_id' => $processingId,
                'error' => $e->getMessage(),
            ]);

            return [
                'found' => false,
                'message' => 'Failed to retrieve detailed logs: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Generate health check information for the processing system
     */
    public function getSystemHealth(): array
    {
        try {
            $health = [
                'overall_status' => 'healthy',
                'checks' => [],
                'statistics' => $this->getProcessingStatistics(),
                'timestamp' => now()->toISOString(),
            ];

            // Check queue health
            $queueHealth = $this->checkQueueHealth();
            $health['checks']['queue'] = $queueHealth;

            // Check storage health
            $storageHealth = $this->checkStorageHealth();
            $health['checks']['storage'] = $storageHealth;

            // Check recent processing success rate
            $processingHealth = $this->checkProcessingHealth();
            $health['checks']['processing'] = $processingHealth;

            // Determine overall status
            $allHealthy = collect($health['checks'])->every(fn ($check) => $check['status'] === 'healthy');
            $health['overall_status'] = $allHealthy ? 'healthy' : 'degraded';

            return $health;
        } catch (\Exception $e) {
            Log::error('Failed to generate system health check', [
                'error' => $e->getMessage(),
            ]);

            return [
                'overall_status' => 'error',
                'message' => 'Failed to generate health check: '.$e->getMessage(),
                'timestamp' => now()->toISOString(),
            ];
        }
    }

    /**
     * Check if processing can be retried automatically
     */
    private function canRetryProcessing(MediaProcessingLog $processingLog): bool
    {
        // Don't retry if it's been marked for manual review
        if (str_contains($processingLog->current_step ?? '', 'manual_review')) {
            return false;
        }

        // Don't retry if it's too old (more than 7 days)
        if ($processingLog->created_at->diffInDays(now()) > 7) {
            return false;
        }

        // Don't retry certain critical failures
        $criticalFailures = [
            'file_not_found',
            'invalid_file_format',
            'storage_failure',
        ];

        foreach ($criticalFailures as $failure) {
            if (str_contains(strtolower($processingLog->error_message ?? ''), $failure)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if processing requires manual review
     */
    private function requiresManualReview(MediaProcessingLog $processingLog): bool
    {
        // Already marked for manual review
        if (str_contains($processingLog->current_step ?? '', 'manual_review')) {
            return true;
        }

        // Multiple failures in critical steps
        $criticalSteps = [
            'creating_sermon_record',
            'transcribing_audio',
        ];

        if (in_array($processingLog->current_step, $criticalSteps)) {
            return true;
        }

        // Check for specific error patterns that require manual intervention
        $manualReviewPatterns = [
            'file not found',
            'invalid audio format',
            'transcription service unavailable',
            'storage failure',
            'database constraint violation',
        ];

        $errorMessage = strtolower($processingLog->error_message ?? '');
        foreach ($manualReviewPatterns as $pattern) {
            if (str_contains($errorMessage, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract log entries related to a processing ID
     */
    private function extractLogEntries(string $processingId): array
    {
        // This is a simplified implementation
        // In a real system, you might want to use a log aggregation service
        // or parse actual log files

        // For now, return a placeholder structure
        // In production, this could integrate with services like ELK stack, Fluentd, etc.
        return [
            'note' => 'Log extraction not implemented - integrate with log aggregation service',
            'suggestion' => 'Check Laravel logs for entries containing processing_id: '.$processingId,
        ];
    }

    /**
     * Generate troubleshooting information
     */
    private function generateTroubleshootingInfo(MediaProcessingLog $processingLog): array
    {
        $troubleshooting = [
            'common_issues' => [],
            'suggested_actions' => [],
            'recovery_options' => [],
        ];

        $currentStep = $processingLog->current_step;
        $errorMessage = strtolower($processingLog->error_message ?? '');

        // Generate step-specific troubleshooting
        switch ($currentStep) {
            case 'creating_sermon_record':
            case 'creating_sermon_record_failed':
                $troubleshooting['common_issues'][] = 'Database connection issues';
                $troubleshooting['common_issues'][] = 'Invalid metadata extraction';
                $troubleshooting['suggested_actions'][] = 'Check database connectivity';
                $troubleshooting['suggested_actions'][] = 'Verify filename format matches expected patterns';
                $troubleshooting['recovery_options'][] = 'Manual sermon record creation';
                break;

            case 'transcribing_audio':
            case 'transcribing_audio_failed':
                $troubleshooting['common_issues'][] = 'Audio file corruption or invalid format';
                $troubleshooting['common_issues'][] = 'OpenAI API rate limits or service unavailable';
                $troubleshooting['common_issues'][] = 'File too large for transcription service';
                $troubleshooting['suggested_actions'][] = 'Verify audio file integrity';
                $troubleshooting['suggested_actions'][] = 'Check OpenAI API status and rate limits';
                $troubleshooting['suggested_actions'][] = 'Consider file compression or chunking';
                $troubleshooting['recovery_options'][] = 'Manual transcription upload';
                $troubleshooting['recovery_options'][] = 'Retry with different transcription service';
                break;

            case 'analyzing_transcript':
            case 'analyzing_transcript_failed':
                $troubleshooting['common_issues'][] = 'AI service rate limits or downtime';
                $troubleshooting['common_issues'][] = 'Transcript content too large or complex';
                $troubleshooting['common_issues'][] = 'Invalid or empty transcript content';
                $troubleshooting['suggested_actions'][] = 'Check AI service status and quotas';
                $troubleshooting['suggested_actions'][] = 'Verify transcript content quality';
                $troubleshooting['recovery_options'][] = 'Apply graceful degradation with fallback values';
                $troubleshooting['recovery_options'][] = 'Manual content analysis and entry';
                break;

            case 'updating_sermon_record':
            case 'updating_sermon_record_failed':
                $troubleshooting['common_issues'][] = 'Database constraint violations';
                $troubleshooting['common_issues'][] = 'Slug generation conflicts';
                $troubleshooting['suggested_actions'][] = 'Check for duplicate slugs or titles';
                $troubleshooting['suggested_actions'][] = 'Verify database schema integrity';
                $troubleshooting['recovery_options'][] = 'Manual record update';
                break;

            case 'sending_notification':
            case 'notification_failed':
                $troubleshooting['common_issues'][] = 'Email service configuration issues';
                $troubleshooting['common_issues'][] = 'Invalid recipient addresses';
                $troubleshooting['suggested_actions'][] = 'Check email service configuration';
                $troubleshooting['suggested_actions'][] = 'Verify admin email addresses';
                $troubleshooting['recovery_options'][] = 'Manual notification';
                $troubleshooting['recovery_options'][] = 'Skip notification step';
                break;
        }

        // Add error-specific troubleshooting
        if (str_contains($errorMessage, 'timeout')) {
            $troubleshooting['common_issues'][] = 'Service timeout - operation took too long';
            $troubleshooting['suggested_actions'][] = 'Increase timeout limits in configuration';
            $troubleshooting['recovery_options'][] = 'Retry with extended timeout';
        }

        if (str_contains($errorMessage, 'rate limit')) {
            $troubleshooting['common_issues'][] = 'API rate limit exceeded';
            $troubleshooting['suggested_actions'][] = 'Wait before retrying or upgrade API plan';
            $troubleshooting['recovery_options'][] = 'Implement exponential backoff retry';
        }

        if (str_contains($errorMessage, 'storage')) {
            $troubleshooting['common_issues'][] = 'Disk space or storage configuration issues';
            $troubleshooting['suggested_actions'][] = 'Check available disk space';
            $troubleshooting['suggested_actions'][] = 'Verify storage permissions';
            $troubleshooting['recovery_options'][] = 'Clean up old files';
        }

        return $troubleshooting;
    }

    /**
     * Check queue health
     */
    private function checkQueueHealth(): array
    {
        // Simplified queue health check
        // In production, you might want to check queue length, failed jobs, etc.
        return [
            'status' => 'healthy',
            'message' => 'Queue system operational',
            'details' => [
                'note' => 'Detailed queue monitoring not implemented',
                'suggestion' => 'Integrate with queue monitoring service',
            ],
        ];
    }

    /**
     * Check storage health
     */
    private function checkStorageHealth(): array
    {
        try {
            $disk = config('media-processing.storage.sermon_disk', 'public');
            $testFile = 'health-check-'.time().'.txt';

            // Try to write and read a test file
            \Illuminate\Support\Facades\Storage::disk($disk)->put($testFile, 'health check');
            $content = \Illuminate\Support\Facades\Storage::disk($disk)->get($testFile);
            \Illuminate\Support\Facades\Storage::disk($disk)->delete($testFile);

            if ($content === 'health check') {
                return [
                    'status' => 'healthy',
                    'message' => 'Storage system operational',
                ];
            } else {
                return [
                    'status' => 'unhealthy',
                    'message' => 'Storage read/write test failed',
                ];
            }
        } catch (\Exception $e) {
            return [
                'status' => 'unhealthy',
                'message' => 'Storage health check failed: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Check processing health
     */
    private function checkProcessingHealth(): array
    {
        try {
            // Check recent success rate (last 24 hours)
            $total = MediaProcessingLog::where('created_at', '>=', now()->subDay())->count();

            if ($total === 0) {
                return [
                    'status' => 'healthy',
                    'message' => 'No recent processing activity',
                ];
            }

            $successful = MediaProcessingLog::where('created_at', '>=', now()->subDay())->completed()->count();
            $successRate = ($successful / $total) * 100;

            if ($successRate >= 80) {
                return [
                    'status' => 'healthy',
                    'message' => "Processing success rate: {$successRate}%",
                    'details' => [
                        'total_recent' => $total,
                        'successful' => $successful,
                        'success_rate' => $successRate,
                    ],
                ];
            } else {
                return [
                    'status' => 'degraded',
                    'message' => "Low processing success rate: {$successRate}%",
                    'details' => [
                        'total_recent' => $total,
                        'successful' => $successful,
                        'success_rate' => $successRate,
                    ],
                ];
            }
        } catch (\Exception $e) {
            return [
                'status' => 'unhealthy',
                'message' => 'Processing health check failed: '.$e->getMessage(),
            ];
        }
    }
}
