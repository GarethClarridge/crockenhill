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

    // -------------------------------------------------------------------------
    // hasOutstandingCanonicalConflict
    // -------------------------------------------------------------------------

    #[Test]
    public function it_returns_false_when_no_canonical_conflict_key_exists(): void
    {
        $this->assertFalse($this->service->hasOutstandingCanonicalConflict([]));
        $this->assertFalse($this->service->hasOutstandingCanonicalConflict(['other_key' => 'value']));
    }

    #[Test]
    public function it_returns_false_when_canonical_conflict_is_not_an_array(): void
    {
        $this->assertFalse($this->service->hasOutstandingCanonicalConflict([
            'canonical_conflict' => 'not-an-array',
        ]));
    }

    #[Test]
    public function it_returns_false_when_review_reopened_is_not_true(): void
    {
        $this->assertFalse($this->service->hasOutstandingCanonicalConflict([
            'canonical_conflict' => [
                'review_reopened' => false,
                'detected_at' => '2026-03-10T10:00:00+00:00',
            ],
        ]));

        $this->assertFalse($this->service->hasOutstandingCanonicalConflict([
            'canonical_conflict' => [
                'detected_at' => '2026-03-10T10:00:00+00:00',
            ],
        ]));
    }

    #[Test]
    public function it_returns_false_when_detected_at_is_missing_or_invalid(): void
    {
        $this->assertFalse($this->service->hasOutstandingCanonicalConflict([
            'canonical_conflict' => [
                'review_reopened' => true,
            ],
        ]));

        $this->assertFalse($this->service->hasOutstandingCanonicalConflict([
            'canonical_conflict' => [
                'review_reopened' => true,
                'detected_at' => 'not-a-date',
            ],
        ]));
    }

    #[Test]
    public function it_returns_true_when_reopened_and_no_reviewed_at_exists(): void
    {
        $this->assertTrue($this->service->hasOutstandingCanonicalConflict([
            'canonical_conflict' => [
                'review_reopened' => true,
                'detected_at' => '2026-03-10T10:00:00+00:00',
            ],
        ]));
    }

    #[Test]
    public function it_returns_true_when_reviewed_at_is_before_detected_at(): void
    {
        $this->assertTrue($this->service->hasOutstandingCanonicalConflict([
            'canonical_conflict' => [
                'review_reopened' => true,
                'detected_at' => '2026-03-15T12:00:00+00:00',
            ],
            'manual_review' => [
                'reviewed_at' => '2026-03-10T10:00:00+00:00',
            ],
        ]));
    }

    #[Test]
    public function it_returns_false_when_reviewed_at_is_after_detected_at(): void
    {
        $this->assertFalse($this->service->hasOutstandingCanonicalConflict([
            'canonical_conflict' => [
                'review_reopened' => true,
                'detected_at' => '2026-03-10T10:00:00+00:00',
            ],
            'manual_review' => [
                'reviewed_at' => '2026-03-15T12:00:00+00:00',
            ],
        ]));
    }

    // -------------------------------------------------------------------------
    // withRecordedCanonicalConflict
    // -------------------------------------------------------------------------

    #[Test]
    public function it_appends_conflict_to_history_and_sets_canonical_conflict_key(): void
    {
        $conflict = [
            'detected_at' => '2026-03-15T10:00:00+00:00',
            'incoming_source' => 'email',
            'review_reopened' => false,
        ];

        $result = $this->service->withRecordedCanonicalConflict([], $conflict);

        $this->assertSame($conflict, $result['canonical_conflict']);
        $this->assertCount(1, $result['canonical_conflict_history']);
        $this->assertSame($conflict, $result['canonical_conflict_history'][0]);
    }

    #[Test]
    public function it_preserves_existing_history_when_appending_a_new_conflict(): void
    {
        $existingConflict = [
            'detected_at' => '2026-03-10T08:00:00+00:00',
            'incoming_source' => 'openlp',
            'review_reopened' => true,
        ];

        $importMetadata = [
            'canonical_conflict' => $existingConflict,
            'canonical_conflict_history' => [$existingConflict],
        ];

        $newConflict = [
            'detected_at' => '2026-03-15T10:00:00+00:00',
            'incoming_source' => 'email',
            'review_reopened' => false,
        ];

        $result = $this->service->withRecordedCanonicalConflict($importMetadata, $newConflict);

        $this->assertSame($newConflict, $result['canonical_conflict']);
        $this->assertCount(2, $result['canonical_conflict_history']);
        $this->assertSame($existingConflict, $result['canonical_conflict_history'][0]);
        $this->assertSame($newConflict, $result['canonical_conflict_history'][1]);
    }

    #[Test]
    public function it_handles_a_corrupt_history_value_by_replacing_it_with_an_array(): void
    {
        $importMetadata = [
            'canonical_conflict_history' => 'corrupt-not-array',
        ];

        $conflict = ['detected_at' => '2026-03-15T10:00:00+00:00', 'incoming_source' => 'manual'];

        $result = $this->service->withRecordedCanonicalConflict($importMetadata, $conflict);

        $this->assertIsArray($result['canonical_conflict_history']);
        $this->assertCount(1, $result['canonical_conflict_history']);
    }
}
