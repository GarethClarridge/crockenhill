<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\ChurchService\ChurchServiceReviewStateService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ChurchServiceReviewStateServiceTest extends TestCase
{
    private ChurchServiceReviewStateService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ChurchServiceReviewStateService;
    }

    #[Test]
    public function it_normalizes_manual_review_columns_without_canonical_conflict_state(): void
    {
        $columns = $this->service->normalizedReviewColumns([
            'manual_review' => [
                'reviewed_at' => '2026-03-10T10:00:00+00:00',
                'reviewed_by_user_id' => 7,
                'reopened_at' => '2026-03-15T12:00:00+00:00',
                'reopened_by_source' => 'email',
            ],
        ]);

        $this->assertSame([
            'review_state' => 'reopened',
            'manual_reviewed_at' => '2026-03-10T10:00:00+00:00',
            'manual_reviewed_by_user_id' => 7,
            'manual_review_reopened_at' => '2026-03-15T12:00:00+00:00',
            'manual_review_reopened_by_source' => 'email',
        ], $columns);
    }

    #[Test]
    public function it_appends_conflicts_to_history_without_storing_a_current_conflict(): void
    {
        $existingConflict = [
            'detected_at' => '2026-03-10T08:00:00+00:00',
            'incoming_source' => 'openlp',
        ];
        $newConflict = [
            'detected_at' => '2026-03-15T10:00:00+00:00',
            'incoming_source' => 'email',
        ];

        $result = $this->service->withCanonicalConflictHistory([
            'canonical_conflict' => $existingConflict,
            'canonical_conflict_history' => [$existingConflict],
        ], $newConflict);

        $this->assertArrayNotHasKey('canonical_conflict', $result);
        $this->assertSame([$existingConflict, $newConflict], $result['canonical_conflict_history']);
    }

    #[Test]
    public function it_replaces_a_corrupt_history_when_recording_a_conflict(): void
    {
        $conflict = [
            'detected_at' => '2026-03-15T10:00:00+00:00',
            'incoming_source' => 'manual',
        ];

        $result = $this->service->withCanonicalConflictHistory([
            'canonical_conflict_history' => 'corrupt-not-array',
        ], $conflict);

        $this->assertSame([$conflict], $result['canonical_conflict_history']);
    }
}
