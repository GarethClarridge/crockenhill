<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\MediaType;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\Processing\ProcessingPhaseRegistry;
use App\Services\Sermon\SermonStorageService;
use Carbon\Carbon;

/**
 * Standardized processing response data structure
 * Used across all processing controllers for consistent API responses
 */
readonly class StandardProcessingResponse
{
    /**
     * @param  array<string, mixed>  $additionalData
     * @param  array<string, mixed>|null  $performanceMetrics
     * @param  array<string, mixed>|null  $errorHistory
     */
    public function __construct(
        public bool $found,
        public ?string $processingId = null,
        public ?string $status = null,
        public ?string $currentStep = null,
        public int $progressPercentage = 0,
        public ?string $errorMessage = null,
        public ?int $sermonId = null,
        public ?string $sermonUrl = null,
        public ?Carbon $startedAt = null,
        public ?Carbon $updatedAt = null,
        public ?string $estimatedCompletion = null,
        public array $additionalData = [],
        public ?ProcessingLogCollection $recentLogs = null,
        public ?array $performanceMetrics = null,
        public ?array $errorHistory = null
    ) {}

    /**
     * Create a successful found response
     *
     * @param  array<string, mixed>  $additionalData
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
     *
     * @param  array<string, mixed>  $additionalData
     * @param  array<string, mixed>|null  $metrics
     * @param  array<string, mixed>|null  $errorHistory
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
     *
     * @return array<string, mixed>
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
     *
     * @param  array<string, mixed>  $additionalData
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
    public static function fromProcessingLog(MediaProcessingLog $log): self
    {
        $metadata = match ($log->processing_type) {
            MediaType::Livestream => [
                'segments_count' => $log->segments->count(),
                'sermon_duration' => $log->extractedSermonMediaDuration()
                    ?? ($log->sermon_end_time && $log->sermon_start_time
                        ? $log->sermon_end_time - $log->sermon_start_time
                        : null),
            ],
            MediaType::Video => [
                'has_thumbnail' => $log->sermon && $log->sermon->hasThumbnail(),
                'video_duration' => $log->duration,
            ],
            MediaType::Audio => [
                'audio_duration' => $log->duration,
            ],
        };

        // Add thumbnail data if sermon exists
        $sermon = $log->sermon;
        if ($sermon instanceof Sermon) {
            $metadata['thumbnail_generated'] = $sermon->hasThumbnail();
            $metadata['thumbnail_url'] = $sermon->hasThumbnail()
                ? app(SermonStorageService::class)->getThumbnailUrl($sermon)
                : null;
            $metadata['thumbnail_generated_at'] = $sermon->thumbnail_generated_at?->toISOString();
        }

        $sermonUrl = null;
        if ($log->sermon instanceof Sermon) {
            $sermonUrl = route('sermons.show', ['sermon' => $log->sermon->slug]);
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
    private static function calculateProgress(MediaProcessingLog $log): int
    {
        if ($log->isComplete()) {
            return 100;
        }

        if ($log->isFailed() || $log->isCancelled()) {
            return 0;
        }

        return app(ProcessingPhaseRegistry::class)->progressForLog($log);
    }
}
