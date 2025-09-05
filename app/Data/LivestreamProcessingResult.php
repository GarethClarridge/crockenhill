<?php

namespace App\Data;

use Spatie\LaravelData\Data;

class LivestreamProcessingResult extends Data
{
    public function __construct(
        public string $processingId,
        public string $status,
        public string $originalFilename,
        public int $fileSize,
        public string $fileFormat,
        public ?float $duration = null,
        public ?float $sermonStartTime = null,
        public ?float $sermonEndTime = null,
        public ?int $sermonId = null,
        public ?string $errorMessage = null,
        public ?array $processingMetadata = null,
        public ?string $startedAt = null,
        public ?string $completedAt = null,
        /** @var LivestreamSegment[] */
        public array $segments = [],
        public ?array $segmentsSummary = null,
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

    public function hasSermon(): bool
    {
        return $this->sermonId !== null;
    }

    public function hasSegments(): bool
    {
        return ! empty($this->segments);
    }

    public function getFileSizeFormatted(): string
    {
        $bytes = $this->fileSize;
        $units = ['B', 'KB', 'MB', 'GB'];
        $power = $bytes > 0 ? floor(log($bytes, 1024)) : 0;

        return number_format($bytes / pow(1024, $power), 2, '.', ',').' '.$units[$power];
    }

    public function getDurationFormatted(): ?string
    {
        if ($this->duration === null) {
            return null;
        }

        $hours = floor($this->duration / 3600);
        $minutes = floor(($this->duration % 3600) / 60);
        $seconds = $this->duration % 60;

        if ($hours > 0) {
            return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
        }

        return sprintf('%02d:%02d', $minutes, $seconds);
    }

    public function getSermonDurationFormatted(): ?string
    {
        if ($this->sermonStartTime === null || $this->sermonEndTime === null) {
            return null;
        }

        $duration = $this->sermonEndTime - $this->sermonStartTime;
        $minutes = floor($duration / 60);
        $seconds = $duration % 60;

        return sprintf('%dm %ds', $minutes, $seconds);
    }

    public function getProgressPercentage(): int
    {
        return match ($this->status) {
            'pending' => 0,
            'processing' => 25,
            'segmentation_complete' => 50,
            'extraction_complete' => 75,
            'sermon_submitted' => 90,
            'completed' => 100,
            'failed' => 0,
            default => 0,
        };
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
}
