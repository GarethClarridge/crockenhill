<?php

namespace App\Data;

use Spatie\LaravelData\Data;

class LivestreamProcessingStatus extends Data
{
    public function __construct(
        public string $processingId,
        public string $status,
        public string $currentStep,
        public int $progressPercentage,
        public ?string $estimatedCompletionTime = null,
        public ?string $errorMessage = null,
        public ?array $stepDetails = null,
        public ?array $processingStats = null,
    ) {}

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isProcessing(): bool
    {
        return in_array($this->status, [
            'processing',
            'segmentation_complete',
            'extraction_complete',
            'sermon_submitted',
        ]);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function getStatusDisplayName(): string
    {
        return match ($this->status) {
            'pending' => 'Pending',
            'processing' => 'Processing',
            'segmentation_complete' => 'Segmentation Complete',
            'extraction_complete' => 'Extraction Complete',
            'sermon_submitted' => 'Sermon Submitted',
            'completed' => 'Completed',
            'failed' => 'Failed',
            default => ucfirst($this->status),
        };
    }

    public function getCurrentStepDisplayName(): string
    {
        return match ($this->currentStep) {
            'rms_generation' => 'Generating RMS Log',
            'segment_analysis' => 'Analyzing Segments',
            'sermon_extraction' => 'Extracting Sermon',
            'sermon_processing' => 'Processing Sermon',
            'cleanup' => 'Cleaning Up',
            'completed' => 'Completed',
            'failed' => 'Failed',
            default => ucfirst(str_replace('_', ' ', $this->currentStep)),
        };
    }

    public function getProgressBarClass(): string
    {
        return match (true) {
            $this->isFailed() => 'bg-red-500',
            $this->isCompleted() => 'bg-green-500',
            $this->isProcessing() => 'bg-blue-500',
            default => 'bg-gray-300',
        };
    }

    public function hasError(): bool
    {
        return $this->errorMessage !== null;
    }

    public function hasStepDetails(): bool
    {
        return $this->stepDetails !== null && ! empty($this->stepDetails);
    }

    public function hasProcessingStats(): bool
    {
        return $this->processingStats !== null && ! empty($this->processingStats);
    }
}
