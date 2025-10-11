<?php

namespace App\Services;

class ProcessingReport
{
    public function __construct(public array $data) {}

    public function toArray(): array
    {
        return $this->data;
    }

    public function toJson(): string
    {
        return json_encode($this->data, JSON_PRETTY_PRINT);
    }

    public function getStatus(): string
    {
        $status = $this->data['status'] ?? 'unknown';

        // Handle ProcessingStatus enum
        if ($status instanceof \App\Enums\ProcessingStatus) {
            return $status->value;
        }

        return $status;
    }

    public function hasErrors(): bool
    {
        return ! empty($this->data['errors']);
    }

    public function hasWarnings(): bool
    {
        return ! empty($this->data['warnings']);
    }

    public function getProcessingDuration(): ?int
    {
        return $this->data['processing_duration_seconds'] ?? null;
    }

    public function getSegmentCount(): int
    {
        return $this->data['total_segments'] ?? 0;
    }
}
