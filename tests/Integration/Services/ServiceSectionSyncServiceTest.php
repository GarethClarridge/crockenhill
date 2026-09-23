<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Actions\HoldSectionForContentReview;
use App\Actions\ServiceReview\ConfirmServiceSection;
use App\Enums\ContentHoldCheck;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionStatus;
use App\Enums\ServiceSectionType;
use App\Exceptions\UnplacedContentHoldException;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\ServiceSection;
use App\Models\User;
use App\Services\ChurchService\ServiceSectionSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServiceSectionSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private const HOLD_REASON = 'Saved text repeats a sentence the audio does not';

    private const HOLD_EVIDENCE = 'docs/plans/HISTORIC-VIDEO-DEFECT-DISCOVERY-AND-ACCEPTANCE-2026-08-29.md';

    private ServiceSectionSyncService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ServiceSectionSyncService::class);
    }

    #[Test]
    public function it_creates_service_sections_for_first_sync(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->create();

        $itemOne = ChurchServiceItem::factory()->create();
        $itemTwo = ChurchServiceItem::factory()->create();

        $this->service->sync($processingLog, [
            $this->sectionData(
                churchServiceItemId: $itemOne->id,
                sectionOrder: 1,
                sectionType: ServiceSectionType::Song->value,
                title: 'Song One'
            ),
            $this->sectionData(
                churchServiceItemId: $itemTwo->id,
                sectionOrder: 2,
                sectionType: ServiceSectionType::Prayer->value,
                title: 'Opening Prayer'
            ),
        ]);

        $this->assertSame(2, $processingLog->serviceSections()->count());
        $this->assertDatabaseHas('service_sections', [
            'media_processing_log_id' => $processingLog->id,
            'section_order' => 1,
            'section_type' => ServiceSectionType::Song->value,
            'title' => 'Song One',
        ]);
        $this->assertDatabaseHas('service_sections', [
            'media_processing_log_id' => $processingLog->id,
            'section_order' => 2,
            'section_type' => ServiceSectionType::Prayer->value,
            'title' => 'Opening Prayer',
        ]);
    }

    #[Test]
    public function it_updates_existing_rows_and_replaces_removed_rows_idempotently(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->create();

        $itemOne = ChurchServiceItem::factory()->create();
        $itemTwo = ChurchServiceItem::factory()->create();
        $itemThree = ChurchServiceItem::factory()->create();

        $this->service->sync($processingLog, [
            $this->sectionData(
                churchServiceItemId: $itemOne->id,
                sectionOrder: 1,
                sectionType: ServiceSectionType::Song->value,
                title: 'Song One'
            ),
            $this->sectionData(
                churchServiceItemId: $itemTwo->id,
                sectionOrder: 2,
                sectionType: ServiceSectionType::Notices->value,
                title: 'Notices'
            ),
        ]);

        $originalOrderOne = ServiceSection::query()
            ->where('media_processing_log_id', $processingLog->id)
            ->where('section_order', 1)
            ->firstOrFail();

        $originalOrderTwo = ServiceSection::query()
            ->where('media_processing_log_id', $processingLog->id)
            ->where('section_order', 2)
            ->firstOrFail();

        $this->service->sync($processingLog, [
            $this->sectionData(
                churchServiceItemId: $itemOne->id,
                sectionOrder: 1,
                sectionType: ServiceSectionType::Song->value,
                title: 'Song One (Updated)',
                startTime: 120.0,
                endTime: 330.0,
                duration: 210.0
            ),
            $this->sectionData(
                churchServiceItemId: $itemThree->id,
                sectionOrder: 3,
                sectionType: ServiceSectionType::Prayer->value,
                title: 'Closing Prayer'
            ),
        ]);

        $updatedOrderOne = ServiceSection::query()
            ->where('media_processing_log_id', $processingLog->id)
            ->where('section_order', 1)
            ->firstOrFail();

        $newOrderThree = ServiceSection::query()
            ->where('media_processing_log_id', $processingLog->id)
            ->where('section_order', 3)
            ->firstOrFail();

        $this->assertSame($originalOrderOne->id, $updatedOrderOne->id);
        $this->assertSame('Song One (Updated)', $updatedOrderOne->title);
        $this->assertSame(120.0, $updatedOrderOne->start_time);
        $this->assertSame(330.0, $updatedOrderOne->end_time);

        $this->assertDatabaseMissing('service_sections', ['id' => $originalOrderTwo->id]);
        $this->assertSame('Closing Prayer', $newOrderThree->title);
        $this->assertSame(2, $processingLog->serviceSections()->count());
    }

    #[Test]
    public function it_supersedes_published_link_and_resets_publishable_rows_when_signature_changes(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        config([
            'media-processing.storage.sermon_disk' => 'public',
            'media-processing.storage.temp_disk' => 'local',
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create();
        $churchServiceItem = ChurchServiceItem::factory()->create();
        $publishedSermon = Sermon::factory()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => $churchServiceItem->id,
            'section_type' => ServiceSectionType::ChildrensTalk->value,
            'section_order' => 1,
            'title' => 'Children Talk',
            'start_time' => 120.0,
            'end_time' => 360.0,
            'publication_status' => ServiceSectionPublicationStatus::Published->value,
            'published_sermon_id' => $publishedSermon->id,
            'published_at' => now(),
            'extracted_video_path' => 'sermons/sections/'.$churchServiceItem->id.'/video.mp4',
            'extracted_audio_path' => 'sermons/audio/section-'.$churchServiceItem->id.'.mp3',
            'extracted_at' => now(),
        ]);

        Storage::disk('public')->put((string) $section->extracted_video_path, 'video');
        Storage::disk('public')->put((string) $section->extracted_audio_path, 'audio');

        $this->service->sync($processingLog, [
            $this->sectionData(
                churchServiceItemId: $churchServiceItem->id,
                sectionOrder: 1,
                sectionType: ServiceSectionType::ChildrensTalk->value,
                title: 'Children Talk Updated',
                startTime: 130.0,
                endTime: 390.0,
                duration: 260.0
            ),
        ]);

        $section->refresh();
        $metadata = $section->metadata?->toArray() ?? [];

        $this->assertSame(ServiceSectionPublicationStatus::NotApplicable, $section->publication_status);
        $this->assertNull($section->published_sermon_id);
        $this->assertNull($section->published_at);
        $this->assertNull($section->extracted_video_path);
        $this->assertNull($section->extracted_audio_path);
        $this->assertNull($section->unpublished_expires_at);
        $this->assertArrayHasKey('superseded', $metadata);
        $this->assertTrue((bool) ($metadata['publishable_type_after_supersede'] ?? false));
        $this->assertDatabaseHas('sermons', ['id' => $publishedSermon->id]);
        Storage::disk('public')->assertMissing('sermons/sections/'.$churchServiceItem->id.'/video.mp4');
        Storage::disk('public')->assertMissing('sermons/audio/section-'.$churchServiceItem->id.'.mp3');
    }

    #[Test]
    public function it_removes_stale_rows_after_detaching_published_links(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        config([
            'media-processing.storage.sermon_disk' => 'public',
            'media-processing.storage.temp_disk' => 'local',
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create();
        $itemOne = ChurchServiceItem::factory()->create();
        $itemTwo = ChurchServiceItem::factory()->create();
        $publishedSermon = Sermon::factory()->create();

        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => $itemOne->id,
            'section_type' => ServiceSectionType::Welcome->value,
            'section_order' => 1,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
        ]);

        $stalePublished = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => $itemTwo->id,
            'section_type' => ServiceSectionType::ChildrensTalk->value,
            'section_order' => 2,
            'publication_status' => ServiceSectionPublicationStatus::Published->value,
            'published_sermon_id' => $publishedSermon->id,
            'extracted_video_path' => 'sermons/sections/'.$itemTwo->id.'/video.mp4',
            'extracted_audio_path' => 'sermons/audio/section-'.$itemTwo->id.'.mp3',
        ]);

        Storage::disk('public')->put('sermons/sections/'.$itemTwo->id.'/video.mp4', 'video');
        Storage::disk('public')->put('sermons/audio/section-'.$itemTwo->id.'.mp3', 'audio');

        $this->service->sync($processingLog, [
            $this->sectionData(
                churchServiceItemId: $itemOne->id,
                sectionOrder: 1,
                sectionType: ServiceSectionType::Welcome->value,
                title: 'Welcome'
            ),
        ]);

        $this->assertDatabaseMissing('service_sections', ['id' => $stalePublished->id]);
        $this->assertDatabaseHas('sermons', ['id' => $publishedSermon->id]);
        Storage::disk('public')->assertMissing('sermons/sections/'.$itemTwo->id.'/video.mp4');
        Storage::disk('public')->assertMissing('sermons/audio/section-'.$itemTwo->id.'.mp3');
    }

    #[Test]
    public function it_allows_sections_without_a_church_service_item_reference(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->create();

        $this->service->sync($processingLog, [
            $this->sectionData(
                churchServiceItemId: null,
                sectionOrder: 1,
                sectionType: ServiceSectionType::Sermon->value,
                startTime: 300.0,
                endTime: 1800.0,
                duration: 1500.0,
                needsManualReview: false,
                classificationMode: 'audio_only'
            ),
        ]);

        $this->assertDatabaseHas('service_sections', [
            'media_processing_log_id' => $processingLog->id,
            'section_order' => 1,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::Sermon->value,
        ]);
    }

    #[Test]
    public function it_preserves_existing_transcript_metadata_when_the_section_signature_is_unchanged(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->create();
        $item = ChurchServiceItem::factory()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => $item->id,
            'section_type' => ServiceSectionType::Prayer->value,
            'section_order' => 1,
            'title' => 'Opening Prayer',
            'start_time' => 60.0,
            'end_time' => 180.0,
            'duration' => 120.0,
            'metadata' => [
                'confidence_level' => 'high',
                'classification_mode' => 'openlp_aligned',
                'transcript' => 'Existing section transcript',
            ],
        ]);

        $this->service->sync($processingLog, [
            $this->sectionData(
                churchServiceItemId: $item->id,
                sectionOrder: 1,
                sectionType: ServiceSectionType::Prayer->value,
                title: 'Opening Prayer'
            ),
        ]);

        $section->refresh();

        $this->assertSame('Existing section transcript', $section->metadata['transcript'] ?? null);
        $this->assertSame('openlp_aligned', $section->metadata['classification_mode'] ?? null);
    }

    #[Test]
    public function a_re_detection_keeps_the_content_hold_on_an_unchanged_section(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->create();
        $section = $this->heldSection($processingLog, ServiceSectionType::Sermon, sectionOrder: 1, startTime: 600.0, endTime: 1800.0);

        $this->service->sync($processingLog, [
            $this->sectionData(null, 1, ServiceSectionType::Sermon->value, startTime: 600.0, endTime: 1800.0, duration: 1200.0),
        ]);

        $this->assertHeld($section->refresh());
    }

    #[Test]
    public function a_re_detection_that_moves_the_held_section_keeps_its_content_hold(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->create();
        $section = $this->heldSection($processingLog, ServiceSectionType::Song, sectionOrder: 1, startTime: 300.0, endTime: 500.0);

        $this->service->sync($processingLog, [
            $this->sectionData(null, 1, ServiceSectionType::Song->value, startTime: 290.0, endTime: 520.0, duration: 230.0),
        ]);

        $section->refresh();

        $this->assertArrayHasKey('superseded', $section->metadata?->toArray() ?? []);
        $this->assertHeld($section);
    }

    /**
     * Rows are kept by order, so after a re-detection shifts the orders the held
     * row can hold different content. The hold follows its content instead.
     */
    #[Test]
    public function a_content_hold_follows_its_content_to_a_new_order(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->create();
        $this->heldSection($processingLog, ServiceSectionType::Sermon, sectionOrder: 2, startTime: 600.0, endTime: 1800.0);

        $this->service->sync($processingLog, [
            $this->sectionData(null, 1, ServiceSectionType::Welcome->value, startTime: 0.0, endTime: 50.0, duration: 50.0),
            $this->sectionData(null, 2, ServiceSectionType::Song->value, startTime: 60.0, endTime: 180.0, duration: 120.0),
            $this->sectionData(null, 3, ServiceSectionType::Sermon->value, startTime: 610.0, endTime: 1790.0, duration: 1180.0),
        ]);

        $this->assertHeld($this->sectionAt($processingLog, 3));
        $this->assertNotHeld($this->sectionAt($processingLog, 2));
    }

    #[Test]
    public function a_content_hold_is_not_spread_to_content_it_does_not_cover(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->create();
        $this->heldSection($processingLog, ServiceSectionType::Song, sectionOrder: 1, startTime: 300.0, endTime: 500.0);

        $this->service->sync($processingLog, [
            $this->sectionData(null, 1, ServiceSectionType::Song->value, startTime: 300.0, endTime: 500.0, duration: 200.0),
            $this->sectionData(null, 2, ServiceSectionType::Song->value, startTime: 600.0, endTime: 800.0, duration: 200.0),
            $this->sectionData(null, 3, ServiceSectionType::Prayer->value, startTime: 400.0, endTime: 450.0, duration: 50.0),
        ]);

        $this->assertHeld($this->sectionAt($processingLog, 1));
        $this->assertNotHeld($this->sectionAt($processingLog, 2));
        $this->assertNotHeld($this->sectionAt($processingLog, 3));
    }

    /**
     * Only an operator releases a hold. A re-detection that no longer finds the
     * held content cannot say whether the defect went with it, so it refuses to
     * write sections rather than let the hold vanish.
     */
    #[Test]
    public function a_re_detection_that_leaves_a_content_hold_nowhere_to_go_writes_nothing(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->create();
        $section = $this->heldSection($processingLog, ServiceSectionType::Sermon, sectionOrder: 1, startTime: 600.0, endTime: 1800.0);

        try {
            $this->service->sync($processingLog, [
                $this->sectionData(null, 1, ServiceSectionType::Song->value, startTime: 60.0, endTime: 180.0, duration: 120.0),
            ]);
            $this->fail('A re-detection dropped a content hold.');
        } catch (UnplacedContentHoldException $exception) {
            $this->assertStringContainsString("section {$section->id}", $exception->getMessage());
        }

        $section->refresh();

        $this->assertSame(ServiceSectionType::Sermon, $section->section_type);
        $this->assertHeld($section);
        $this->assertSame(1, $processingLog->serviceSections()->count());
    }

    #[Test]
    public function a_released_content_hold_stays_released_and_keeps_its_history(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->create();
        $section = $this->heldSection($processingLog, ServiceSectionType::Sermon, sectionOrder: 1, startTime: 600.0, endTime: 1800.0);
        app(ConfirmServiceSection::class)->execute($section->refresh(), User::factory()->create()->id);

        $this->service->sync($processingLog, [
            $this->sectionData(null, 1, ServiceSectionType::Sermon->value, startTime: 590.0, endTime: 1810.0, duration: 1220.0),
        ]);

        $section->refresh();

        $this->assertNotHeld($section);
        $this->assertCount(1, $section->metadata?->toArray()[HoldSectionForContentReview::METADATA_KEY] ?? []);
    }

    /**
     * A re-detection can merge as well as move: the 09-17 song trim turned two
     * detected songs into one, and the canary only proved holds carry when the
     * replacement is one-for-one. Both holds must land on the surviving row —
     * losing either would release content an operator proved wrong.
     */
    #[Test]
    public function a_merge_into_one_section_carries_every_hold_it_covers(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->create();
        $first = $this->heldSection($processingLog, ServiceSectionType::Song, sectionOrder: 1, startTime: 300.0, endTime: 420.0);
        $second = $this->heldSection($processingLog, ServiceSectionType::Song, sectionOrder: 2, startTime: 430.0, endTime: 560.0);
        app(HoldSectionForContentReview::class)($second, 'Second song is misidentified', 'canary-20260917-merge', ContentHoldCheck::Judgement);

        // One song now covers the span both held sections occupied.
        $this->service->sync($processingLog, [
            $this->sectionData(null, 1, ServiceSectionType::Song->value, startTime: 300.0, endTime: 560.0, duration: 260.0),
        ]);

        $this->assertSame(1, $processingLog->serviceSections()->count());

        $merged = $this->sectionAt($processingLog, 1);
        $this->assertHeld($merged);

        $reasons = array_column(
            $merged->metadata?->toArray()[HoldSectionForContentReview::METADATA_KEY] ?? [],
            'reason'
        );
        $this->assertContains(self::HOLD_REASON, $reasons, "The first section's hold was lost in the merge.");
        $this->assertContains('Second song is misidentified', $reasons, "The second section's hold was lost in the merge.");
        $this->assertCount(2, $reasons, 'Each covered hold is recorded once.');

        $this->assertDatabaseMissing('service_sections', ['id' => $second->id]);
        unset($first);
    }

    /**
     * The other direction: a split leaves a hold covering two rows. Holding both
     * is the safe error — an operator can release what turns out to be sound,
     * but nothing can recover content released while still wrong.
     */
    #[Test]
    public function a_split_holds_every_section_the_held_content_still_covers(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->create();
        $this->heldSection($processingLog, ServiceSectionType::Song, sectionOrder: 1, startTime: 300.0, endTime: 560.0);

        // The macro song splits into the two real songs inside it.
        $this->service->sync($processingLog, [
            $this->sectionData(null, 1, ServiceSectionType::Song->value, startTime: 300.0, endTime: 420.0, duration: 120.0),
            $this->sectionData(null, 2, ServiceSectionType::Song->value, startTime: 430.0, endTime: 560.0, duration: 130.0),
        ]);

        $this->assertHeld($this->sectionAt($processingLog, 1));
        $this->assertHeld($this->sectionAt($processingLog, 2));
    }

    /**
     * Run 1287, 2026-09-23: re-detection trimmed the held song and inserted a
     * reading after it, and the hold's record stayed on the row at the old order —
     * the reading — describing a defect the reading never had.
     */
    #[Test]
    public function hold_records_follow_their_content_not_the_row_they_were_on(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->create();
        $this->heldSection($processingLog, ServiceSectionType::Song, sectionOrder: 1, startTime: 190.0, endTime: 297.0);

        $this->service->sync($processingLog, [
            $this->sectionData(null, 1, ServiceSectionType::Song->value, startTime: 189.6, endTime: 277.0, duration: 87.4),
            $this->sectionData(null, 2, ServiceSectionType::BibleReading->value, startTime: 277.0, endTime: 298.0, duration: 21.0),
        ]);

        $this->assertHeld($this->sectionAt($processingLog, 1));

        $reading = $this->sectionAt($processingLog, 2);
        $this->assertNotHeld($reading);
        $this->assertArrayNotHasKey(
            HoldSectionForContentReview::METADATA_KEY,
            $reading->metadata?->toArray() ?? [],
            'The reading never carried this content; its record must not stay behind on the row.',
        );
    }

    #[Test]
    public function a_released_hold_record_moves_with_its_content_and_stays_released(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->create();
        $sermon = $this->heldSection($processingLog, ServiceSectionType::Sermon, sectionOrder: 1, startTime: 600.0, endTime: 1800.0);
        app(ConfirmServiceSection::class)->execute($sermon->refresh(), User::factory()->create()->id);

        // A prayer is inserted before the sermon, so the sermon moves to order 2.
        $this->service->sync($processingLog, [
            $this->sectionData(null, 1, ServiceSectionType::Prayer->value, startTime: 500.0, endTime: 590.0, duration: 90.0),
            $this->sectionData(null, 2, ServiceSectionType::Sermon->value, startTime: 600.0, endTime: 1800.0, duration: 1200.0),
        ]);

        $this->assertArrayNotHasKey(HoldSectionForContentReview::METADATA_KEY, $this->sectionAt($processingLog, 1)->metadata?->toArray() ?? []);

        $moved = $this->sectionAt($processingLog, 2);
        $this->assertNotHeld($moved);
        $this->assertCount(1, $moved->metadata?->toArray()[HoldSectionForContentReview::METADATA_KEY] ?? []);
    }

    private function heldSection(
        MediaProcessingLog $processingLog,
        ServiceSectionType $type,
        int $sectionOrder,
        float $startTime,
        float $endTime,
    ): ServiceSection {
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => null,
            'section_type' => $type->value,
            'section_order' => $sectionOrder,
            'title' => null,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'duration' => $endTime - $startTime,
            'needs_manual_review' => false,
            'metadata' => ['review_flags' => []],
        ]);

        app(HoldSectionForContentReview::class)($section, self::HOLD_REASON, self::HOLD_EVIDENCE, ContentHoldCheck::Judgement);

        return $section->refresh();
    }

    private function sectionAt(MediaProcessingLog $processingLog, int $sectionOrder): ServiceSection
    {
        return ServiceSection::query()
            ->where('media_processing_log_id', $processingLog->id)
            ->where('section_order', $sectionOrder)
            ->firstOrFail();
    }

    private function assertHeld(ServiceSection $section): void
    {
        $metadata = $section->metadata?->toArray() ?? [];

        $this->assertTrue($section->needs_manual_review, "Section {$section->id} must stay in manual review.");
        $this->assertContains(HoldSectionForContentReview::FLAG, $metadata['review_flags'] ?? [], "Section {$section->id} must carry the hold flag.");
        $this->assertSame(self::HOLD_REASON, $metadata[HoldSectionForContentReview::METADATA_KEY][0]['reason'] ?? null);
        $this->assertSame(self::HOLD_EVIDENCE, $metadata[HoldSectionForContentReview::METADATA_KEY][0]['evidence'] ?? null);
    }

    private function assertNotHeld(ServiceSection $section): void
    {
        $this->assertFalse($section->needs_manual_review, "Section {$section->id} must not be held.");
        $this->assertNotContains(HoldSectionForContentReview::FLAG, $section->metadata?->toArray()['review_flags'] ?? []);
    }

    /**
     * @return array{
     *     church_service_item_id: int|null,
     *     section_type: string,
     *     section_order: int,
     *     title: ?string,
     *     start_time: float,
     *     end_time: float,
     *     duration: float,
     *     status: string,
     *     needs_manual_review: bool,
     *     source_segment_ids: array<int, int>,
     *     metadata: array<string, mixed>
     * }
     */
    private function sectionData(
        ?int $churchServiceItemId,
        int $sectionOrder,
        string $sectionType,
        ?string $title = null,
        float $startTime = 60.0,
        float $endTime = 180.0,
        float $duration = 120.0,
        bool $needsManualReview = false,
        string $classificationMode = 'openlp_aligned'
    ): array {
        return [
            'church_service_item_id' => $churchServiceItemId,
            'section_type' => $sectionType,
            'section_order' => $sectionOrder,
            'title' => $title,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'duration' => $duration,
            'status' => ServiceSectionStatus::Identified->value,
            'needs_manual_review' => $needsManualReview,
            'source_segment_ids' => [1],
            'metadata' => [
                'confidence_level' => 'high',
                'classification_mode' => $classificationMode,
            ],
        ];
    }
}
