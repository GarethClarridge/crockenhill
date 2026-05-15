<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\SermonVideoQualityStatus;

final readonly class SermonVideoQualityAssessmentResult
{
    /**
     * @param  list<float>  $sampleTimestamps
     * @param  array<string, mixed>  $metrics
     */
    public function __construct(
        public SermonVideoQualityStatus $status,
        public ?string $reason,
        public int $sampleCount,
        public array $sampleTimestamps,
        public float $blankFrameRatio,
        public float $frozenPairRatio,
        public float $lowDetailRatio,
        public float $aggregateScore,
        public array $metrics = [],
    ) {}

    public static function failed(string $reason = 'analysis_failed'): self
    {
        return new self(
            status: SermonVideoQualityStatus::Unassessed,
            reason: $reason,
            sampleCount: 0,
            sampleTimestamps: [],
            blankFrameRatio: 0.0,
            frozenPairRatio: 0.0,
            lowDetailRatio: 0.0,
            aggregateScore: 0.0,
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
            'sample_count' => $this->sampleCount,
            'sample_timestamps' => $this->sampleTimestamps,
            'blank_frame_ratio' => $this->blankFrameRatio,
            'frozen_pair_ratio' => $this->frozenPairRatio,
            'low_detail_ratio' => $this->lowDetailRatio,
            'aggregate_score' => $this->aggregateScore,
            'metrics' => $this->metrics,
        ];
    }
}
