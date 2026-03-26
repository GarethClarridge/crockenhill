<?php

declare(strict_types=1);

namespace Tests\Feature\Actions\ServiceReview;

use App\Actions\ServiceReview\ResolvePendingStructureMerge;
use App\Enums\ChurchServiceItemSource;
use App\Enums\SermonService;
use App\Enums\ServiceSectionType;
use App\Events\ChurchServiceCanonicalListChanged;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ResolvePendingStructureMergeTest extends TestCase
{
    use RefreshDatabase;

    private ResolvePendingStructureMerge $action;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake();
        $this->action = app(ResolvePendingStructureMerge::class);
        $this->admin = User::factory()->create(['is_admin' => true]);
    }

    #[Test]
    public function accept_incoming_replaces_canonical_items_with_proposed(): void
    {
        $service = $this->createServiceWithPendingMerge();

        $result = $this->action->execute($service, 'accept_incoming', $this->admin->id);

        $this->assertTrue($result->applied);
        $this->assertSame('accept_incoming', $result->resolution);

        $items = $result->churchService->items()->orderBy('position')->get();
        $this->assertSame('How Great Thou Art', $items[0]->title);
        $this->assertSame('Opening Prayer', $items[1]->title);
    }

    #[Test]
    public function accept_incoming_fires_canonical_list_changed_event(): void
    {
        $service = $this->createServiceWithPendingMerge();

        $this->action->execute($service, 'accept_incoming', $this->admin->id);

        Event::assertDispatched(ChurchServiceCanonicalListChanged::class);
    }

    #[Test]
    public function accept_incoming_clears_pending_merge_metadata(): void
    {
        $service = $this->createServiceWithPendingMerge();

        $result = $this->action->execute($service, 'accept_incoming', $this->admin->id);

        $metadata = $result->churchService->import_metadata?->toArray() ?? [];
        $this->assertArrayNotHasKey('pending_structure_merge', $metadata);
    }

    #[Test]
    public function accept_incoming_records_resolution_metadata(): void
    {
        $service = $this->createServiceWithPendingMerge();

        $result = $this->action->execute($service, 'accept_incoming', $this->admin->id);

        $metadata = $result->churchService->import_metadata?->toArray() ?? [];
        $this->assertArrayHasKey('structure_merge_resolution', $metadata);
        $this->assertSame('accept_incoming', $metadata['structure_merge_resolution']['resolution']);
        $this->assertSame($this->admin->id, $metadata['structure_merge_resolution']['resolved_by_user_id']);
        $this->assertSame('openlp', $metadata['structure_merge_resolution']['original_incoming_source']);
        $this->assertSame(2, $metadata['structure_merge_resolution']['conflict_count']);
    }

    #[Test]
    public function accept_incoming_clears_needs_review_flag(): void
    {
        $service = $this->createServiceWithPendingMerge();

        $result = $this->action->execute($service, 'accept_incoming', $this->admin->id);

        $this->assertFalse($result->churchService->needs_review);
    }

    #[Test]
    public function accept_incoming_sets_reviewed_state(): void
    {
        $service = $this->createServiceWithPendingMerge();

        $result = $this->action->execute($service, 'accept_incoming', $this->admin->id);

        $metadata = $result->churchService->import_metadata?->toArray() ?? [];
        $this->assertArrayHasKey('manual_review', $metadata);
        $this->assertSame($this->admin->id, $metadata['manual_review']['reviewed_by_user_id']);
    }

    #[Test]
    public function keep_current_preserves_existing_canonical_items(): void
    {
        $service = $this->createServiceWithPendingMerge();

        $result = $this->action->execute($service, 'keep_current', $this->admin->id);

        $this->assertTrue($result->applied);
        $this->assertSame('keep_current', $result->resolution);

        $items = $result->churchService->items()->orderBy('position')->get();
        $this->assertSame('Amazing Grace', $items[0]->title);
        $this->assertSame('Sermon', $items[1]->title);
    }

    #[Test]
    public function keep_current_does_not_fire_canonical_list_changed(): void
    {
        $service = $this->createServiceWithPendingMerge();

        $this->action->execute($service, 'keep_current', $this->admin->id);

        Event::assertNotDispatched(ChurchServiceCanonicalListChanged::class);
    }

    #[Test]
    public function keep_current_clears_pending_merge_metadata(): void
    {
        $service = $this->createServiceWithPendingMerge();

        $result = $this->action->execute($service, 'keep_current', $this->admin->id);

        $metadata = $result->churchService->import_metadata?->toArray() ?? [];
        $this->assertArrayNotHasKey('pending_structure_merge', $metadata);
    }

    #[Test]
    public function keep_current_records_resolution_metadata(): void
    {
        $service = $this->createServiceWithPendingMerge();

        $result = $this->action->execute($service, 'keep_current', $this->admin->id);

        $metadata = $result->churchService->import_metadata?->toArray() ?? [];
        $this->assertArrayHasKey('structure_merge_resolution', $metadata);
        $this->assertSame('keep_current', $metadata['structure_merge_resolution']['resolution']);
        $this->assertSame($this->admin->id, $metadata['structure_merge_resolution']['resolved_by_user_id']);
    }

    #[Test]
    public function keep_current_clears_needs_review_flag(): void
    {
        $service = $this->createServiceWithPendingMerge();

        $result = $this->action->execute($service, 'keep_current', $this->admin->id);

        $this->assertFalse($result->churchService->needs_review);
    }

    #[Test]
    public function returns_not_applied_when_no_pending_merge_exists(): void
    {
        $service = ChurchService::factory()->create([
            'needs_review' => false,
            'import_metadata' => [],
        ]);

        $result = $this->action->execute($service, 'accept_incoming', $this->admin->id);

        $this->assertFalse($result->applied);
        $this->assertStringContainsString('No pending structure merge', $result->reason);
    }

    #[Test]
    public function returns_not_applied_for_unknown_resolution_type(): void
    {
        $service = $this->createServiceWithPendingMerge();

        $result = $this->action->execute($service, 'invalid_option', $this->admin->id);

        $this->assertFalse($result->applied);
        $this->assertStringContainsString('Unknown resolution', $result->reason);
    }

    #[Test]
    public function preserves_existing_import_metadata_fields(): void
    {
        $service = $this->createServiceWithPendingMerge([
            'openlp_import' => ['imported_at' => '2026-03-26T10:00:00Z'],
        ]);

        $result = $this->action->execute($service, 'keep_current', $this->admin->id);

        $metadata = $result->churchService->import_metadata?->toArray() ?? [];
        $this->assertArrayHasKey('openlp_import', $metadata);
    }

    /**
     * @param  array<string, mixed>  $extraMetadata
     */
    private function createServiceWithPendingMerge(array $extraMetadata = []): ChurchService
    {
        $service = ChurchService::factory()->create([
            'date' => '2026-03-23',
            'service' => SermonService::MORNING->value,
            'source' => ChurchServiceItemSource::LIVESTREAM->value,
            'needs_review' => true,
            'import_metadata' => array_merge($extraMetadata, [
                'pending_structure_merge' => [
                    'incoming_source' => 'openlp',
                    'created_at' => now()->toIso8601String(),
                    'confidence' => [
                        'current' => 'high',
                        'incoming' => 'high',
                    ],
                    'conflicts' => [
                        [
                            'type' => 'title_conflict',
                            'current_item' => ['position' => 1, 'type' => 'songs', 'title' => 'Amazing Grace'],
                            'incoming_item' => ['position' => 1, 'type' => 'songs', 'title' => 'How Great Thou Art'],
                        ],
                        [
                            'type' => 'type_conflict',
                            'current_item' => ['position' => 2, 'type' => 'custom', 'title' => 'Sermon', 'section_type' => 'sermon'],
                            'incoming_item' => ['position' => 2, 'type' => 'custom', 'title' => 'Opening Prayer', 'section_type' => 'prayer'],
                        ],
                    ],
                    'proposed_items' => [
                        ['position' => 1, 'type' => 'songs', 'title' => 'How Great Thou Art', 'source_title' => null, 'openlp_search_title' => 'how great@', 'song_id' => null, 'metadata' => null],
                        ['position' => 2, 'type' => 'custom', 'title' => 'Opening Prayer', 'section_type' => ServiceSectionType::PRAYER->value, 'source_title' => null, 'openlp_search_title' => null, 'song_id' => null, 'metadata' => null],
                    ],
                    'classification' => [
                        'auto_merge' => [],
                        'review_required' => [0, 1],
                        'unmatched_incoming' => [],
                    ],
                ],
            ]),
        ]);

        ChurchServiceItem::factory()->livestream()->create([
            'church_service_id' => $service->id,
            'position' => 1,
            'type' => 'songs',
            'title' => 'Amazing Grace',
            'section_type' => ServiceSectionType::SONG,
            'metadata' => [
                'livestream_projection' => [
                    'processing_id' => 'test',
                    'service_section_id' => 1,
                    'source_segment_ids' => [],
                    'confidence_level' => 'high',
                ],
            ],
        ]);

        ChurchServiceItem::factory()->livestream()->create([
            'church_service_id' => $service->id,
            'position' => 2,
            'type' => 'custom',
            'title' => 'Sermon',
            'section_type' => ServiceSectionType::SERMON,
            'metadata' => [
                'livestream_projection' => [
                    'processing_id' => 'test',
                    'service_section_id' => 2,
                    'source_segment_ids' => [],
                    'confidence_level' => 'high',
                ],
            ],
        ]);

        return $service;
    }
}
