<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\SermonVideoQualityStatus;

final readonly class SermonVideoQualityAssessmentResult
{
    /**
     * @param  array<string, mixed>  $metrics
     */
    public function __construct(
        public SermonVideoQualityStatus $status,
        public ?string $reason,
        public int $windowCount,
        public int $deadWindowCount,
        public float $deadWindowRatio,
        public float $freezeSeconds,
        public float $blackSeconds,
        public float $measuredSeconds,
        public array $metrics = [],
    ) {}

    public static function failed(string $reason = 'analysis_failed'): self
    {
        return new self(
            status: SermonVideoQualityStatus::Unassessed,
            reason: $reason,
            windowCount: 0,
            deadWindowCount: 0,
            deadWindowRatio: 0.0,
            freezeSeconds: 0.0,
            blackSeconds: 0.0,
            measuredSeconds: 0.0,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'reason' => $this->reason,
            'window_count' => $this->windowCount,
            'dead_window_count' => $this->deadWindowCount,
            'dead_window_ratio' => $this->deadWindowRatio,
            'freeze_seconds' => $this->freezeSeconds,
            'black_seconds' => $this->blackSeconds,
            'measured_seconds' => $this->measuredSeconds,
            'metrics' => $this->metrics,
        ];
    }
}
