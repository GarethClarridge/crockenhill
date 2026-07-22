<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\MediaProcessingLog;

final readonly class ChurchServiceProcessingRunView
{
    /**
     * @param  list<array<string, mixed>>  $processingTimeline
     * @param  list<array<string, mixed>>  $serviceTimeline
     * @param  list<array<string, mixed>>  $serviceFlow
     */
    public function __construct(
        public MediaProcessingLog $run,
        public array $processingTimeline,
        public array $serviceTimeline,
        public array $serviceFlow,
        public bool $hasSections,
        public bool $isInProgress,
        public bool $needsSermonReview,
        public bool $needsSectionReview,
        public bool $hasPendingPublications,
    ) {}

    public function hasProcessingTimeline(): bool
    {
        return $this->processingTimeline !== [];
    }

    public function isWaitingForSections(): bool
    {
        return $this->isInProgress && ! $this->hasSections;
    }

    public function hasClassifiedSections(): bool
    {
        return $this->hasSections && $this->serviceTimeline !== [];
    }

    public function isFailedWithoutSections(): bool
    {
        return $this->run->isFailed()
            && ! $this->hasSections
            && ! $this->needsSermonReview;
    }

    public function hasReviewActions(): bool
    {
        return $this->needsSermonReview
            || $this->needsSectionReview
            || $this->hasPendingPublications;
    }
}
