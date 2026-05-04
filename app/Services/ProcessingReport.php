<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ProcessingStatus;

class ProcessingReport
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(public readonly array $data) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    public function toJson(): string
    {
        $json = json_encode($this->data, JSON_PRETTY_PRINT);

        return $json === false ? '{}' : $json;
    }

    public function getStatus(): string
    {
        $status = $this->data['status'] ?? 'unknown';

        // Handle ProcessingStatus enum
        if ($status instanceof ProcessingStatus) {
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
