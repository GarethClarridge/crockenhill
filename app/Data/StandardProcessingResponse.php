<?php

namespace App\Data;

use Carbon\Carbon;

/**
 * Standardized processing response data structure
 * Used across all processing controllers for consistent API responses
 */
class StandardProcessingResponse
{
    public function __construct(
        public readonly bool $found,
        public readonly ?string $processingId = null,
        public readonly ?string $status = null,
        public readonly ?string $currentStep = null,
        public readonly int $progressPercentage = 0,
        public readonly ?string $errorMessage = null,
        public readonly ?int $sermonId = null,
        public readonly ?string $sermonUrl = null,
        public readonly ?Carbon $startedAt = null,
        public readonly ?Carbon $updatedAt = null,
        public readonly ?string $estimatedCompletion = null,
        public readonly array $additionalData = [],
        public readonly ?ProcessingLogCollection $recentLogs = null,
        public readonly ?array $performanceMetrics = null,
        public readonly ?array $errorHistory = null
    ) {}

    /**
     * Create a successful found response
     */
    public static function found(
        string $processingId,
        string $status,
        ?string $currentStep = null,
        int $progressPercentage = 0,
        ?string $errorMessage = null,
        ?int $sermonId = null,
        ?string $sermonUrl = null,
        ?Carbon $startedAt = null,
        ?Carbon $updatedAt = null,
        ?string $estimatedCompletion = null,
        array $additionalData = []
    ): self {
        return new self(
            found: true,
            processingId: $processingId,
            status: $status,
            currentStep: $currentStep,
            progressPercentage: $progressPercentage,
            errorMessage: $errorMessage,
            sermonId: $sermonId,
            sermonUrl: $sermonUrl,
            startedAt: $startedAt,
            updatedAt: $updatedAt,
            estimatedCompletion: $estimatedCompletion,
            additionalData: $additionalData
        );
    }

    /**
     * Create a successful found response with logs and metrics
     */
    public static function withLogs(
        string $processingId,
        string $status,
        ?string $currentStep = null,
        int $progressPercentage = 0,
        ?string $errorMessage = null,
        ?int $sermonId = null,
        ?string $sermonUrl = null,
        ?Carbon $startedAt = null,
        ?Carbon $updatedAt = null,
        ?string $estimatedCompletion = null,
        array $additionalData = [],
        ?ProcessingLogCollection $logs = null,
        ?array $metrics = null,
        ?array $errorHistory = null
    ): self {
        return new self(
            found: true,
            processingId: $processingId,
            status: $status,
            currentStep: $currentStep,
            progressPercentage: $progressPercentage,
            errorMessage: $errorMessage,
            sermonId: $sermonId,
            sermonUrl: $sermonUrl,
            startedAt: $startedAt,
            updatedAt: $updatedAt,
            estimatedCompletion: $estimatedCompletion,
            additionalData: $additionalData,
            recentLogs: $logs,
            performanceMetrics: $metrics,
            errorHistory: $errorHistory
        );
    }

    /**
     * Create a not found response
     */
    public static function notFound(): self
    {
        return new self(found: false);
    }

    /**
     * Create an error response
     */
    public static function error(string $errorMessage): self
    {
        return new self(
            found: false,
            errorMessage: $errorMessage
        );
    }

    /**
     * Check if processing is complete
     */
    public function isComplete(): bool
    {
        return $this->found && $this->status === 'completed';
    }

    /**
     * Check if processing has failed
     */
    public function isFailed(): bool
    {
        return $this->found && $this->status === 'failed';
    }

    /**
     * Check if processing was cancelled
     */
    public function isCancelled(): bool
    {
        return $this->found && $this->status === 'cancelled';
    }

    /**
     * Check if processing is in progress
     */
    public function isInProgress(): bool
    {
        return $this->found && in_array($this->status, ['pending', 'processing']);
    }

    /**
     * Convert to array for JSON response
     */
    public function toArray(): array
    {
        if (! $this->found) {
            return [
                'found' => false,
                'message' => $this->errorMessage ?? 'Processing record not found',
            ];
        }

        $response = [
            'found' => $this->found,
            'processing_id' => $this->processingId,
            'status' => $this->status,
            'current_step' => $this->currentStep,
            'progress_percentage' => $this->progressPercentage,
            'started_at' => $this->startedAt?->toISOString(),
            'updated_at' => $this->updatedAt?->toISOString(),
            'created_at' => $this->startedAt?->toISOString(), // Backwards compatibility
        ];

        if ($this->errorMessage) {
            $response['error_message'] = $this->errorMessage;
        }

        if ($this->estimatedCompletion) {
            $response['estimated_completion'] = $this->estimatedCompletion;
        }

        if ($this->sermonId) {
            $response['sermon_id'] = $this->sermonId;
        }

        if ($this->sermonUrl) {
            $response['sermon_url'] = $this->sermonUrl;
        }

        // Include logs if present
        if ($this->recentLogs) {
            $response['recent_logs'] = $this->recentLogs->toArray();
        }

        // Include performance metrics if present
        if ($this->performanceMetrics) {
            $response['performance_metrics'] = $this->performanceMetrics;
        }

        // Include error history if present
        if ($this->errorHistory) {
            $response['error_history'] = $this->errorHistory;
        }

        // Merge any additional data
        if (! empty($this->additionalData)) {
            $response = array_merge($response, $this->additionalData);
        }

        return $response;
    }

    /**
     * Create response from processing status with flexible input
     */
    public static function fromProcessingStatus(
        string $processingId,
        string $status,
        ?string $currentStep = null,
        int $progressPercentage = 0,
        ?string $errorMessage = null,
        ?int $sermonId = null,
        ?string $sermonUrl = null,
        ?Carbon $startedAt = null,
        ?Carbon $updatedAt = null,
        ?string $estimatedCompletion = null,
        array $additionalData = []
    ): self {
        return self::found(
            processingId: $processingId,
            status: $status,
            currentStep: $currentStep,
            progressPercentage: $progressPercentage,
            errorMessage: $errorMessage,
            sermonId: $sermonId,
            sermonUrl: $sermonUrl,
            startedAt: $startedAt,
            updatedAt: $updatedAt,
            estimatedCompletion: $estimatedCompletion,
            additionalData: $additionalData
        );
    }

    /**
     * Create response from MediaProcessingLog
     */
    public static function fromProcessingLog(\App\Models\MediaProcessingLog $log): self
    {
        $metadata = match ($log->processing_type) {
            'livestream' => [
                'segments_count' => $log->segments->count(),
                'sermon_duration' => $log->sermon_end_time && $log->sermon_start_time
                    ? $log->sermon_end_time - $log->sermon_start_time
                    : null,
            ],
            'video' => [
                'has_thumbnail' => $log->sermon && ! empty($log->sermon->thumbnail_file_path),
                'video_duration' => $log->duration,
            ],
            'audio' => [
                'audio_duration' => $log->duration,
            ],
            default => [],
        };

        // Add thumbnail data if sermon exists
        $sermon = $log->sermon;
        if ($sermon instanceof \App\Models\Sermon) {
            $metadata['thumbnail_generated'] = ! empty($sermon->thumbnail_file_path);
            $metadata['thumbnail_url'] = $sermon->thumbnail_file_path
                ? \Illuminate\Support\Facades\Storage::disk('public')->url($sermon->thumbnail_file_path)
                : null;
            $metadata['thumbnail_generated_at'] = $sermon->thumbnail_generated_at?->toISOString();
        }

        $sermonUrl = null;
        if ($log->sermon instanceof \App\Models\Sermon) {
            $sermonUrl = "/christ/sermons/{$log->sermon->slug}";
        }

        return self::found(
            processingId: $log->processing_id,
            status: $log->status->value,
            currentStep: $log->current_step,
            progressPercentage: self::calculateProgress($log),
            errorMessage: $log->error_message,
            sermonId: $log->sermon_id,
            sermonUrl: $sermonUrl,
            startedAt: $log->started_at,
            updatedAt: $log->updated_at,
            additionalData: $metadata
        );
    }

    /**
     * Calculate progress percentage based on current step
     */
    private static function calculateProgress(\App\Models\MediaProcessingLog $log): int
    {
        if ($log->isComplete()) {
            return 100;
        }

        if ($log->isFailed() || $log->isCancelled()) {
            return 0;
        }

        // Simple progress calculation based on common steps
        $stepProgress = match ($log->current_step) {
            'audio_processing_initiated', 'video_processing_initiated' => 10,
            'visual_analysis' => 10,
            'validating' => 15,
            'rms_generation' => 20,
            'extracting_audio' => 25,
            'segmentation' => 30,
            'analyzing_segments' => 40,
            'extraction', 'extracting_sermon' => 50,
            'sermon_creation', 'creating_sermon' => 60,
            'transcribing_audio', 'transcription_completed' => 70,
            'analyzing_transcript', 'ai_analysis_completed' => 85,
            'generating_thumbnail' => 90,
            'cleanup' => 95,
            default => 50,
        };

        return $stepProgress;
    }
}
