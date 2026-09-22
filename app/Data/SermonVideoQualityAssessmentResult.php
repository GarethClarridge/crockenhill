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
        public float $durationSeconds,
        public float $deadSeconds,
        public float $usableShare,
        public float $freezeSeconds,
        public float $blackSeconds,
        public array $metrics = [],
    ) {}

    public static function failed(string $reason = 'analysis_failed'): self
    {
        return new self(
            status: SermonVideoQualityStatus::Unassessed,
            reason: $reason,
            durationSeconds: 0.0,
            deadSeconds: 0.0,
            usableShare: 0.0,
            freezeSeconds: 0.0,
            blackSeconds: 0.0,
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
            'duration_seconds' => $this->durationSeconds,
            'dead_seconds' => $this->deadSeconds,
            'usable_share' => $this->usableShare,
            'freeze_seconds' => $this->freezeSeconds,
            'black_seconds' => $this->blackSeconds,
            'metrics' => $this->metrics,
        ];
    }
}
