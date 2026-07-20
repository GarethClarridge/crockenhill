<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Enums\ChurchServiceCanonicalConflictReason;
use App\Enums\ChurchServiceCanonicalConflictState;
use App\Enums\ChurchServiceItemSource;
use App\Enums\ChurchServiceReviewState;
use App\Enums\SermonService;
use App\Events\ChurchServiceCanonicalListChanged;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Services\ChurchService\ChurchServiceCanonicalStateService;
use App\Services\ChurchService\ChurchServiceCanonicalUpdateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChurchServiceCanonicalUpdateServiceTest extends TestCase
{
    use RefreshDatabase;

    private ChurchServiceCanonicalUpdateService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ChurchServiceCanonicalUpdateService::class);
    }

    #[Test]
    public function it_does_not_write_metadata_or_fire_event_when_nothing_changed(): void
    {
        Event::fake();

        $churchService = ChurchService::factory()->create([
            'service' => SermonService::Morning,
            'needs_review' => false,
            'import_metadata' => ['confidence_score' => 1.0],
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'source' => ChurchServiceItemSource::OpenLp->value,
            'title' => 'Great is thy faithfulness',
        ]);

        $canonicalState = app(ChurchServiceCanonicalStateService::class);
        $before = $canonicalState->snapshot($churchService->load('items'));

        $result = $this->service->finalize($churchService, $before, ChurchServiceItemSource::OpenLp);

        $result->refresh();

        $this->assertFalse($result->needs_review);
        $this->assertNull($result->import_metadata['canonical_conflict'] ?? null);
        $this->assertNull($result->import_metadata['canonical_conflict_history'] ?? null);
        $this->assertSame(ChurchServiceCanonicalConflictState::None, $result->canonical_conflict_state);
        $this->assertSame(ChurchServiceReviewState::NotReviewed, $result->review_state);

        Event::assertNotDispatched(ChurchServiceCanonicalListChanged::class);
    }

    #[Test]
    public function it_treats_first_population_as_a_change_without_recording_a_conflict(): void
    {
        $churchService = ChurchService::factory()->create([
            'needs_review' => false,
            'import_metadata' => ['confidence_score' => 1.0],
        ]);
        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'source' => ChurchServiceItemSource::OpenLp,
            'title' => 'Great is thy faithfulness',
        ]);

        Event::fake();

        $result = $this->service->finalize($churchService, [], ChurchServiceItemSource::OpenLp);

        $this->assertFalse($result->needs_review);
        $this->assertNull($result->import_metadata['canonical_conflict'] ?? null);
        $this->assertNull($result->import_metadata['canonical_conflict_history'] ?? null);
        $this->assertSame(ChurchServiceCanonicalConflictState::None, $result->canonical_conflict_state);
        Event::assertDispatched(ChurchServiceCanonicalListChanged::class);
    }

    #[Test]
    public function it_records_a_canonical_conflict_when_items_change_on_an_unreviewed_service(): void
    {
        Event::fake();

        $churchService = ChurchService::factory()->create([
            'service' => SermonService::Morning,
            'needs_review' => false,
            'import_metadata' => ['confidence_score' => 1.0],
        ]);

        $item = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'source' => ChurchServiceItemSource::OpenLp->value,
            'title' => 'Before the throne',
        ]);

        $canonicalState = app(ChurchServiceCanonicalStateService::class);
        $before = $canonicalState->snapshot($churchService->load('items'));

        // Mutate the item after taking the snapshot
        $item->update(['title' => 'Before the throne of God above']);

        $result = $this->service->finalize($churchService, $before, ChurchServiceItemSource::Email);

        $result->refresh();

        // An unreviewed service with changes records the conflict but does not force needs_review
        // (review_reopened is false; hasOutstandingCanonicalConflict only triggers for reopened conflicts)
        $this->assertFalse($result->needs_review);
        $this->assertIsArray($result->import_metadata['canonical_conflict']);
        $this->assertSame('email', $result->import_metadata['canonical_conflict']['incoming_source']);
        $this->assertTrue($result->import_metadata['canonical_conflict']['canonical_changed']);
        $this->assertFalse($result->import_metadata['canonical_conflict']['review_reopened']);
        $this->assertFalse($result->import_metadata['canonical_conflict']['reviewed_previously']);
        $this->assertCount(1, $result->import_metadata['canonical_conflict_history']);
        $this->assertSame(ChurchServiceCanonicalConflictState::Detected, $result->canonical_conflict_state);
        $this->assertSame(ChurchServiceCanonicalConflictReason::CanonicalChanged, $result->canonical_conflict_reason);

        Event::assertDispatched(
            ChurchServiceCanonicalListChanged::class,
            fn (ChurchServiceCanonicalListChanged $event): bool => $event->churchServiceId === $result->id
                && $event->source === 'email'
        );
    }

    #[Test]
    public function it_reopens_manual_review_when_items_change_on_a_previously_reviewed_service(): void
    {
        Event::fake();

        $churchService = ChurchService::factory()->create([
            'service' => SermonService::Morning,
            'needs_review' => false,
            'import_metadata' => [
                'confidence_score' => 1.0,
                'manual_review' => [
                    'reviewed_at' => '2026-03-10T10:00:00+00:00',
                    'reviewed_by' => 'admin@example.com',
                ],
            ],
        ]);

        $itemToUpdate = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'source' => ChurchServiceItemSource::Email->value,
            'title' => 'Amazing grace',
        ]);

        $canonicalState = app(ChurchServiceCanonicalStateService::class);
        $before = $canonicalState->snapshot($churchService->load('items'));

        $itemToUpdate->update(['title' => 'Amazing Grace (How sweet the sound)']);

        $result = $this->service->finalize($churchService, $before, ChurchServiceItemSource::OpenLp);

        $result->refresh();

        $this->assertTrue($result->needs_review);
        $this->assertTrue($result->import_metadata['canonical_conflict']['review_reopened']);
        $this->assertSame('openlp', $result->import_metadata['manual_review']['reopened_by_source'] ?? null);
        $this->assertArrayHasKey('reopened_at', $result->import_metadata['manual_review']);
        $this->assertSame(ChurchServiceReviewState::Reopened, $result->review_state);
        $this->assertSame(ChurchServiceCanonicalConflictState::Reopened, $result->canonical_conflict_state);
    }

    #[Test]
    public function it_does_not_fire_event_for_conflicts_only_when_no_canonical_list_change_occurred(): void
    {
        Event::fake();

        $churchService = ChurchService::factory()->create([
            'service' => SermonService::Morning,
            'needs_review' => false,
            'import_metadata' => ['confidence_score' => 1.0],
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'source' => ChurchServiceItemSource::OpenLp->value,
            'title' => 'Be thou my vision',
        ]);

        $canonicalState = app(ChurchServiceCanonicalStateService::class);
        $before = $canonicalState->snapshot($churchService->load('items'));

        // Pass a syncResult with conflicts but no actual item changes
        $result = $this->service->finalize(
            $churchService,
            $before,
            ChurchServiceItemSource::OpenLp,
            ['conflicts' => [['field' => 'title', 'conflict' => 'duplicate']]]
        );

        $result->refresh();

        $this->assertTrue($result->needs_review);
        $this->assertFalse($result->import_metadata['canonical_conflict']['canonical_changed']);
        $this->assertSame(ChurchServiceCanonicalConflictReason::ConflictsOnly, $result->canonical_conflict_reason);

        // No canonical list change — event must not fire
        Event::assertNotDispatched(ChurchServiceCanonicalListChanged::class);
    }

    #[Test]
    public function it_accepts_a_string_source_and_resolves_it_to_the_enum(): void
    {
        Event::fake();

        $churchService = ChurchService::factory()->create([
            'service' => SermonService::Morning,
            'needs_review' => false,
            'import_metadata' => ['confidence_score' => 1.0],
        ]);

        $item = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'source' => ChurchServiceItemSource::OpenLp->value,
            'title' => 'How great thou art',
        ]);

        $canonicalState = app(ChurchServiceCanonicalStateService::class);
        $before = $canonicalState->snapshot($churchService->load('items'));

        $item->update(['title' => 'How great Thou art (updated)']);

        // Pass a raw string instead of enum
        $result = $this->service->finalize($churchService, $before, 'manual');

        $result->refresh();

        $this->assertSame('manual', $result->import_metadata['canonical_conflict']['incoming_source']);
    }
}
