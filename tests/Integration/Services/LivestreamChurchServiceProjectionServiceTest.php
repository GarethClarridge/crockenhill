<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Enums\ChurchServiceItemSource;
use App\Enums\SermonService;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\LivestreamChurchServiceProjectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LivestreamChurchServiceProjectionServiceTest extends TestCase
{
    use RefreshDatabase;

    private LivestreamChurchServiceProjectionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake();
        $this->service = app(LivestreamChurchServiceProjectionService::class);
    }

    #[Test]
    public function test_creates_new_service_and_items_when_no_matching_service_exists(): void
    {
        $log = $this->createProcessingLog('2026-03-23', SermonService::Morning);

        $this->createSections($log, [
            ['type' => ServiceSectionType::Song, 'title' => 'Amazing Grace', 'confidence' => 0.95],
            ['type' => ServiceSectionType::Sermon, 'title' => 'The Prodigal Son', 'confidence' => 0.9],
            ['type' => ServiceSectionType::Prayer, 'title' => 'Closing Prayer', 'confidence' => 0.85],
        ]);

        $result = $this->service->project($log);

        $this->assertTrue($result['projected']);
        $this->assertSame('Created new service from livestream projection', $result['reason']);
        $this->assertSame(3, $result['items_projected']);

        $churchService = ChurchService::query()->find($result['church_service_id']);

        $this->assertNotNull($churchService);
        $this->assertSame('2026-03-23', $churchService->date->toDateString());
        $this->assertSame(SermonService::Morning, $churchService->service);
        $this->assertSame(ChurchServiceItemSource::Livestream->value, $churchService->source);

        $items = $churchService->items()->orderBy('position')->get();

        $this->assertCount(3, $items);
        $this->assertSame('Amazing Grace', $items[0]->title);
        $this->assertSame(ChurchServiceItemSource::Livestream, $items[0]->source);
        $this->assertSame('songs', $items[0]->type);
        $this->assertSame('The Prodigal Son', $items[1]->title);
        $this->assertSame('Closing Prayer', $items[2]->title);

        $log->refresh();
        $this->assertSame($churchService->id, $log->church_service_id);
    }

    #[Test]
    public function test_links_sections_back_to_projected_items(): void
    {
        $log = $this->createProcessingLog('2026-03-23', SermonService::Morning);

        $sections = $this->createSections($log, [
            ['type' => ServiceSectionType::Song, 'title' => 'Song A', 'confidence' => 0.9],
            ['type' => ServiceSectionType::Sermon, 'title' => 'Sermon', 'confidence' => 0.9],
        ]);

        $result = $this->service->project($log);

        $this->assertTrue($result['projected']);

        foreach ($sections as $section) {
            $section->refresh();
            $this->assertNotNull(
                $section->church_service_item_id,
                "Section '{$section->title}' should be linked to a projected item"
            );
        }

        $linkedItemIds = collect($sections)->map(fn ($s) => $s->fresh()->church_service_item_id)->unique()->values();
        $this->assertCount(2, $linkedItemIds, 'Each section should be linked to a distinct item');
    }

    #[Test]
    public function test_refreshes_existing_livestream_only_service(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-03-23',
            'service' => SermonService::Morning->value,
            'source' => ChurchServiceItemSource::Livestream->value,
        ]);

        ChurchServiceItem::factory()->livestream()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'title' => 'Old Song',
        ]);

        $log = $this->createProcessingLog('2026-03-23', SermonService::Morning);

        $this->createSections($log, [
            ['type' => ServiceSectionType::Song, 'title' => 'New Song', 'confidence' => 0.9],
            ['type' => ServiceSectionType::Sermon, 'title' => 'New Sermon', 'confidence' => 0.85],
        ]);

        $result = $this->service->project($log);

        $this->assertTrue($result['projected']);
        $this->assertSame('Refreshed existing livestream-only service', $result['reason']);
        $this->assertSame($churchService->id, $result['church_service_id']);

        $items = $churchService->fresh()->items()->orderBy('position')->get();

        $this->assertSame(2, $items->count());
        $this->assertSame('New Song', $items[0]->title);
        $this->assertSame('New Sermon', $items[1]->title);
    }

    #[Test]
    public function test_skips_projection_when_non_livestream_items_exist(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-03-23',
            'service' => SermonService::Morning->value,
            'source' => 'openlp',
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'title' => 'OpenLP Song',
            'source' => ChurchServiceItemSource::OpenLp->value,
        ]);

        $log = $this->createProcessingLog('2026-03-23', SermonService::Morning);

        $this->createSections($log, [
            ['type' => ServiceSectionType::Song, 'title' => 'Livestream Song', 'confidence' => 0.9],
        ]);

        $result = $this->service->project($log);

        $this->assertFalse($result['projected']);
        $this->assertStringContainsString('non-livestream items', $result['reason']);
        $this->assertSame($churchService->id, $result['church_service_id']);

        $items = $churchService->fresh()->items;
        $this->assertCount(1, $items);
        $this->assertSame('OpenLP Song', $items->first()->title);
    }

    #[Test]
    public function test_opens_service_review_when_skipping_projection_with_flagged_sections(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-03-23',
            'service' => SermonService::Morning->value,
            'source' => 'openlp',
            'needs_review' => false,
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'title' => 'OpenLP Song',
            'source' => ChurchServiceItemSource::OpenLp->value,
        ]);

        $log = $this->createProcessingLog('2026-03-23', SermonService::Morning);
        [$section] = $this->createSections($log, [
            ['type' => ServiceSectionType::Sermon, 'title' => 'Low Confidence Sermon', 'confidence' => 0.4],
        ]);
        $section->forceFill(['needs_manual_review' => true])->save();

        $result = $this->service->project($log);

        $this->assertFalse($result['projected']);
        $this->assertTrue(
            $churchService->fresh()->needs_review,
            'Section review state must roll up to the OoS-backed service even though projection skips.'
        );
    }

    #[Test]
    public function test_opens_service_review_when_every_section_is_filtered_out_of_projection(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-03-23',
            'service' => SermonService::Morning->value,
            'source' => 'openlp',
            'needs_review' => false,
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'title' => 'OpenLP Song',
            'source' => ChurchServiceItemSource::OpenLp->value,
        ]);

        $log = $this->createProcessingLog('2026-03-23', SermonService::Morning);

        // An OTHER-typed section is excluded by the mapper, so nothing is
        // projectable — but it still needs manual review, and that must reach
        // the service inbox rather than dying at the filtering early-return.
        [$section] = $this->createSections($log, [
            ['type' => ServiceSectionType::Other, 'title' => 'Unclassifiable block', 'confidence' => 0.3],
        ]);
        $section->forceFill(['needs_manual_review' => true])->save();

        $result = $this->service->project($log);

        $this->assertFalse($result['projected']);
        $this->assertStringContainsString('No projectable sections', $result['reason']);
        $this->assertSame($churchService->id, $log->fresh()->church_service_id, 'The run still links to the matching service.');
        $this->assertTrue(
            $churchService->fresh()->needs_review,
            'A flagged run must reach the inbox even when every section is filtered out of projection.'
        );
    }

    #[Test]
    public function test_leaves_service_review_closed_when_skipping_projection_with_clean_sections(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-03-23',
            'service' => SermonService::Morning->value,
            'source' => 'openlp',
            'needs_review' => false,
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'title' => 'OpenLP Song',
            'source' => ChurchServiceItemSource::OpenLp->value,
        ]);

        $log = $this->createProcessingLog('2026-03-23', SermonService::Morning);
        $this->createSections($log, [
            ['type' => ServiceSectionType::Song, 'title' => 'Livestream Song', 'confidence' => 0.9],
        ]);

        $result = $this->service->project($log);

        $this->assertFalse($result['projected']);
        $this->assertFalse($churchService->fresh()->needs_review);
    }

    #[Test]
    public function test_skips_when_identity_cannot_be_resolved(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => null,
            'extracted_service' => null,
            'processing_metadata' => null,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::Song,
            'section_order' => 1,
            'confidence' => 0.9,
        ]);

        $result = $this->service->project($log);

        $this->assertFalse($result['projected']);
        $this->assertStringContainsString('identity', $result['reason']);
    }

    #[Test]
    public function test_skips_when_no_sections_exist(): void
    {
        $log = $this->createProcessingLog('2026-03-23', SermonService::Morning);

        $result = $this->service->project($log);

        $this->assertFalse($result['projected']);
        $this->assertStringContainsString('No classified sections', $result['reason']);
    }

    #[Test]
    public function test_skips_when_all_sections_are_filtered_out(): void
    {
        $log = $this->createProcessingLog('2026-03-23', SermonService::Morning);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::Other,
            'section_order' => 1,
            'confidence' => 0.9,
        ]);

        $result = $this->service->project($log);

        $this->assertFalse($result['projected']);
        $this->assertStringContainsString('No projectable sections', $result['reason']);
    }

    #[Test]
    public function test_sets_needs_review_when_sections_have_low_confidence(): void
    {
        $log = $this->createProcessingLog('2026-03-23', SermonService::Morning);

        $this->createSections($log, [
            ['type' => ServiceSectionType::Song, 'title' => 'Song', 'confidence' => 0.9],
            ['type' => ServiceSectionType::Sermon, 'title' => 'Sermon', 'confidence' => 0.4],
        ]);

        $result = $this->service->project($log);

        $this->assertTrue($result['projected']);

        $churchService = ChurchService::query()->find($result['church_service_id']);
        $this->assertTrue($churchService->needs_review);
    }

    #[Test]
    public function test_sets_needs_review_when_sections_flagged_for_manual_review(): void
    {
        $log = $this->createProcessingLog('2026-03-23', SermonService::Morning);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::Song,
            'section_order' => 1,
            'title' => 'Song',
            'confidence' => 0.9,
            'needs_manual_review' => true,
        ]);

        $result = $this->service->project($log);

        $this->assertTrue($result['projected']);

        $churchService = ChurchService::query()->find($result['church_service_id']);
        $this->assertTrue($churchService->needs_review);
    }

    #[Test]
    public function test_stores_projection_metadata_on_service(): void
    {
        $log = $this->createProcessingLog('2026-03-23', SermonService::Morning);

        $this->createSections($log, [
            ['type' => ServiceSectionType::Song, 'title' => 'Song', 'confidence' => 0.9],
        ]);

        $result = $this->service->project($log);

        $churchService = ChurchService::query()->find($result['church_service_id']);
        $importMetadata = $churchService->import_metadata?->toArray() ?? [];

        $this->assertArrayHasKey('livestream_projection', $importMetadata);
        $this->assertArrayHasKey('projected_at', $importMetadata['livestream_projection']);
        $this->assertArrayHasKey('confidence_summary', $importMetadata['livestream_projection']);
        $this->assertArrayNotHasKey('processing_id', $importMetadata['livestream_projection']);
    }

    #[Test]
    public function test_stores_projection_metadata_on_items(): void
    {
        $log = $this->createProcessingLog('2026-03-23', SermonService::Morning);

        $this->createSections($log, [
            ['type' => ServiceSectionType::Song, 'title' => 'Song', 'confidence' => 0.9],
        ]);

        $result = $this->service->project($log);

        $churchService = ChurchService::query()->find($result['church_service_id']);
        $item = $churchService->items()->first();

        $this->assertSame($log->processing_id, $item->livestream_processing_id);
        $this->assertArrayHasKey('livestream_projection', $item->metadata);
        $this->assertSame('high', $item->metadata['livestream_projection']['confidence_level']);
        $this->assertArrayNotHasKey('processing_id', $item->metadata['livestream_projection']);
        $this->assertArrayNotHasKey('service_section_id', $item->metadata['livestream_projection']);
    }

    #[Test]
    public function test_does_not_create_duplicate_service_on_rerun(): void
    {
        $log = $this->createProcessingLog('2026-03-23', SermonService::Morning);

        $this->createSections($log, [
            ['type' => ServiceSectionType::Song, 'title' => 'Song A', 'confidence' => 0.9],
        ]);

        $firstResult = $this->service->project($log);
        $this->assertTrue($firstResult['projected']);

        ServiceSection::query()->where('media_processing_log_id', $log->id)->delete();

        $this->createSections($log, [
            ['type' => ServiceSectionType::Song, 'title' => 'Song B', 'confidence' => 0.9],
            ['type' => ServiceSectionType::Sermon, 'title' => 'Sermon', 'confidence' => 0.85],
        ]);

        $secondResult = $this->service->project($log);
        $this->assertTrue($secondResult['projected']);
        $this->assertSame($firstResult['church_service_id'], $secondResult['church_service_id']);

        $serviceCount = ChurchService::query()
            ->whereDate('date', '2026-03-23')
            ->where('service', SermonService::Morning->value)
            ->count();

        $this->assertSame(1, $serviceCount);
    }

    #[Test]
    public function test_links_processing_log_to_existing_service_even_when_skipped(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-03-23',
            'service' => SermonService::Morning->value,
            'source' => 'openlp',
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'source' => ChurchServiceItemSource::OpenLp->value,
        ]);

        $log = $this->createProcessingLog('2026-03-23', SermonService::Morning);

        $this->createSections($log, [
            ['type' => ServiceSectionType::Song, 'title' => 'Song', 'confidence' => 0.9],
        ]);

        $result = $this->service->project($log);

        $this->assertFalse($result['projected']);

        $log->refresh();
        $this->assertSame($churchService->id, $log->church_service_id);
    }

    #[Test]
    public function test_does_not_set_needs_review_for_filtered_out_low_confidence_section(): void
    {
        // Regression for fix #3: an OTHER-type section with low confidence that was
        // excluded by the mapper must not trigger needs_review on the projected service.
        $log = $this->createProcessingLog('2026-03-27', SermonService::Morning);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::Song,
            'section_order' => 1,
            'title' => 'Song',
            'confidence' => 0.9,
            'needs_manual_review' => false,
        ]);

        // This section is excluded by the mapper (OTHER type) and should not influence needs_review
        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::Other,
            'section_order' => 2,
            'title' => 'Unknown',
            'confidence' => 0.2,
            'needs_manual_review' => false,
        ]);

        $result = $this->service->project($log);

        $this->assertTrue($result['projected']);
        $this->assertSame(1, $result['items_projected'], 'Only the SONG section should be projected');

        $churchService = ChurchService::query()->find($result['church_service_id']);
        $this->assertFalse($churchService->needs_review, 'needs_review must not be set by the excluded OTHER section');
    }

    #[Test]
    public function test_stores_confidence_summary_on_service_projection_metadata(): void
    {
        $log = $this->createProcessingLog('2026-03-27', SermonService::Morning);

        $this->createSections($log, [
            ['type' => ServiceSectionType::Song, 'title' => 'Song A', 'confidence' => 0.95],
            ['type' => ServiceSectionType::Sermon, 'title' => 'Sermon', 'confidence' => 0.6],
            ['type' => ServiceSectionType::Prayer, 'title' => 'Prayer', 'confidence' => 0.35],
        ]);

        $result = $this->service->project($log);

        $churchService = ChurchService::query()->find($result['church_service_id']);
        $projection = $churchService->import_metadata?->toArray()['livestream_projection'] ?? [];

        $this->assertArrayHasKey('confidence_summary', $projection);
        $this->assertSame(1, $projection['confidence_summary']['high'], 'Song A should be high');
        $this->assertSame(1, $projection['confidence_summary']['medium'], 'Sermon should be medium');
        $this->assertSame(1, $projection['confidence_summary']['low'], 'Prayer should be low');
    }

    private function createProcessingLog(string $date, SermonService $service): MediaProcessingLog
    {
        return MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => $date,
            'extracted_service' => $service->value,
        ]);
    }

    /**
     * @param  array<int, array{type: ServiceSectionType, title: string, confidence: float}>  $sectionData
     * @return list<ServiceSection>
     */
    private function createSections(MediaProcessingLog $log, array $sectionData): array
    {
        $sections = [];

        foreach ($sectionData as $index => $data) {
            $sections[] = ServiceSection::factory()->create([
                'media_processing_log_id' => $log->id,
                'church_service_item_id' => null,
                'section_type' => $data['type'],
                'section_order' => $index + 1,
                'title' => $data['title'],
                'confidence' => $data['confidence'],
                'needs_manual_review' => false,
            ]);
        }

        return $sections;
    }
}
