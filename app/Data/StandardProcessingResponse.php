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
     * Create response from legacy ProcessingStatusResult
     */
    public static function fromProcessingStatusResult(\App\Services\ProcessingStatusResult $result): self
    {
        if (! $result->found) {
            return self::notFound();
        }

        return self::found(
            processingId: $result->processingId,
            status: $result->status->value,
            currentStep: $result->currentStep,
            progressPercentage: 0, // Will be calculated separately
            errorMessage: $result->errorMessage,
            sermonId: $result->sermonId,
            sermonUrl: $result->sermonSlug ? "/christ/sermons/{$result->sermonSlug}" : null,
            startedAt: $result->createdAt,
            updatedAt: $result->updatedAt
        );
    }
}
