<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionStatus;
use App\Enums\ServiceSectionType;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\ServiceSection;
use App\Services\ServiceSectionSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServiceSectionSyncServiceTest extends TestCase
{
    use RefreshDatabase;

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
                sectionType: ServiceSectionType::SONG->value,
                title: 'Song One'
            ),
            $this->sectionData(
                churchServiceItemId: $itemTwo->id,
                sectionOrder: 2,
                sectionType: ServiceSectionType::PRAYER->value,
                title: 'Opening Prayer'
            ),
        ]);

        $this->assertSame(2, $processingLog->serviceSections()->count());
        $this->assertDatabaseHas('service_sections', [
            'media_processing_log_id' => $processingLog->id,
            'section_order' => 1,
            'section_type' => ServiceSectionType::SONG->value,
            'title' => 'Song One',
        ]);
        $this->assertDatabaseHas('service_sections', [
            'media_processing_log_id' => $processingLog->id,
            'section_order' => 2,
            'section_type' => ServiceSectionType::PRAYER->value,
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
                sectionType: ServiceSectionType::SONG->value,
                title: 'Song One'
            ),
            $this->sectionData(
                churchServiceItemId: $itemTwo->id,
                sectionOrder: 2,
                sectionType: ServiceSectionType::NOTICES->value,
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
                sectionType: ServiceSectionType::SONG->value,
                title: 'Song One (Updated)',
                startTime: 120.0,
                endTime: 330.0,
                duration: 210.0
            ),
            $this->sectionData(
                churchServiceItemId: $itemThree->id,
                sectionOrder: 3,
                sectionType: ServiceSectionType::PRAYER->value,
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
            'section_type' => ServiceSectionType::CHILDRENS_TALK->value,
            'section_order' => 1,
            'title' => 'Children Talk',
            'start_time' => 120.0,
            'end_time' => 360.0,
            'publication_status' => ServiceSectionPublicationStatus::PUBLISHED->value,
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
                sectionType: ServiceSectionType::CHILDRENS_TALK->value,
                title: 'Children Talk Updated',
                startTime: 130.0,
                endTime: 390.0,
                duration: 260.0
            ),
        ]);

        $section->refresh();
        $metadata = $section->metadata?->toArray() ?? [];

        $this->assertSame(ServiceSectionPublicationStatus::NOT_APPLICABLE, $section->publication_status);
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
            'section_type' => ServiceSectionType::WELCOME->value,
            'section_order' => 1,
            'publication_status' => ServiceSectionPublicationStatus::NOT_APPLICABLE->value,
        ]);

        $stalePublished = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => $itemTwo->id,
            'section_type' => ServiceSectionType::CHILDRENS_TALK->value,
            'section_order' => 2,
            'publication_status' => ServiceSectionPublicationStatus::PUBLISHED->value,
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
                sectionType: ServiceSectionType::WELCOME->value,
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
                sectionType: ServiceSectionType::SERMON->value,
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
            'section_type' => ServiceSectionType::SERMON->value,
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
            'section_type' => ServiceSectionType::PRAYER->value,
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
                sectionType: ServiceSectionType::PRAYER->value,
                title: 'Opening Prayer'
            ),
        ]);

        $section->refresh();

        $this->assertSame('Existing section transcript', $section->metadata['transcript'] ?? null);
        $this->assertSame('openlp_aligned', $section->metadata['classification_mode'] ?? null);
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
            'status' => ServiceSectionStatus::IDENTIFIED->value,
            'needs_manual_review' => $needsManualReview,
            'source_segment_ids' => [1],
            'metadata' => [
                'confidence_level' => 'high',
                'classification_mode' => $classificationMode,
            ],
        ];
    }
}
