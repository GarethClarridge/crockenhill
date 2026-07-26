<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Enums\ChurchServiceItemSource;
use App\Enums\ProcessingStatus;
use App\Enums\SermonService;
use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Services\ChurchService\LivestreamChurchServiceProjectionService;
use App\Services\Public\PublicSongUsageService;
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
    public function test_projects_llm_content_fields_onto_the_service(): void
    {
        $log = $this->createProcessingLog('2026-03-23', SermonService::Morning);
        $log->forceFill([
            'processing_metadata' => [
                'service_structure' => [
                    'summary' => 'The service welcomes the congregation and teaches from Joshua chapter one.',
                    'notices' => [[
                        'title' => 'Holiday club',
                        'details' => 'Registration opens next week.',
                    ]],
                    'chapter_markers' => [[
                        'title' => 'Sermon',
                        'start_time' => 600.0,
                        'end_time' => 2200.0,
                    ]],
                ],
            ],
        ])->save();

        $this->createSections($log, [
            ['type' => ServiceSectionType::Sermon, 'title' => 'The faithfulness of God', 'confidence' => 0.95],
        ]);

        $result = $this->service->project($log);
        $churchService = ChurchService::query()->findOrFail($result['church_service_id']);

        $this->assertSame('The service welcomes the congregation and teaches from Joshua chapter one.', $churchService->summary);
        $this->assertSame([
            ['title' => 'Holiday club', 'details' => 'Registration opens next week.'],
        ], $churchService->notices);
        $this->assertSame('Sermon', $churchService->chapter_markers[0]['title']);
        $this->assertEqualsWithDelta(600.0, (float) $churchService->chapter_markers[0]['start_time'], 0.01);
        $this->assertEqualsWithDelta(2200.0, (float) $churchService->chapter_markers[0]['end_time'], 0.01);
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
    public function test_merges_projection_when_non_livestream_items_exist(): void
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
        $log->forceFill([
            'processing_metadata' => [
                'service_structure' => [
                    'summary' => 'The service summary remains useful alongside the order of service.',
                    'notices' => [['title' => 'Holiday club', 'details' => 'Registration opens next week.']],
                    'chapter_markers' => [['title' => 'Sermon', 'start_time' => 600.0, 'end_time' => 2200.0]],
                ],
            ],
        ])->save();

        $this->createSections($log, [
            ['type' => ServiceSectionType::Song, 'title' => 'Livestream Song', 'confidence' => 0.9],
        ]);

        $result = $this->service->project($log);

        $this->assertTrue($result['projected']);
        $this->assertSame($churchService->id, $result['church_service_id']);

        $items = $churchService->fresh()->items()->orderBy('position')->get();
        $this->assertCount(2, $items);
        $this->assertSame('OpenLP Song', $items[0]->title);
        $this->assertSame('Livestream Song', $items[1]->title);
        $this->assertSame(ChurchServiceItemSource::OpenLp, $items[0]->source);
        $this->assertSame(ChurchServiceItemSource::Livestream, $items[1]->source);
        $this->assertSame(
            'The service summary remains useful alongside the order of service.',
            $churchService->fresh()->summary
        );
    }

    #[Test]
    public function test_opens_service_review_when_merging_projection_with_flagged_sections(): void
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

        $this->assertTrue($result['projected']);
        $this->assertTrue(
            $churchService->fresh()->needs_review,
            'Section review state must roll up to the OoS-backed service when projection merges.'
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
    public function test_leaves_service_review_closed_when_merging_projection_with_clean_sections(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-03-23',
            'service' => SermonService::Morning->value,
            'source' => 'openlp',
            'needs_review' => false,
        ]);

        foreach ([[1, 'Opening Song'], [2, 'Closing Song']] as [$position, $title]) {
            ChurchServiceItem::factory()->create([
                'church_service_id' => $churchService->id,
                'position' => $position,
                'type' => 'songs',
                'title' => $title,
                'source' => ChurchServiceItemSource::OpenLp->value,
            ]);
        }

        // A genuinely clean run: it recognises both planned songs and adds only
        // the speech items the order of service never listed.
        $log = $this->createProcessingLog('2026-03-23', SermonService::Morning);
        $this->createSections($log, [
            ['type' => ServiceSectionType::Song, 'title' => 'Opening Song', 'confidence' => 0.9],
            ['type' => ServiceSectionType::Sermon, 'title' => 'The faithfulness of God', 'confidence' => 0.9],
            ['type' => ServiceSectionType::Song, 'title' => 'Closing Song', 'confidence' => 0.9],
        ]);

        $result = $this->service->project($log);

        $this->assertTrue($result['projected']);
        $this->assertFalse(
            $churchService->fresh()->needs_review,
            'A clean detected run filling gaps in the order of service is routine, not reviewable.',
        );
    }

    #[Test]
    public function test_preserves_openlp_song_identity_when_a_livestream_section_matches_it(): void
    {
        $song = Song::factory()->create(['title' => 'Amazing Grace']);

        $churchService = ChurchService::factory()->create([
            'date' => '2026-03-23',
            'service' => SermonService::Morning->value,
            'source' => 'openlp',
            'needs_review' => false,
        ]);

        $openLpItem = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'title' => 'Amazing Grace',
            'openlp_search_title' => 'amazinggrace',
            'song_id' => $song->id,
            'source' => ChurchServiceItemSource::OpenLp->value,
        ]);

        $log = $this->createProcessingLog('2026-03-23', SermonService::Morning);
        $sections = $this->createSections($log, [
            ['type' => ServiceSectionType::Song, 'title' => 'Amazing Grace', 'confidence' => 0.9],
        ]);

        $result = $this->service->project($log);

        $this->assertTrue($result['projected']);

        $openLpItem->refresh();
        $this->assertSame('Amazing Grace', $openLpItem->title);
        $this->assertSame('amazinggrace', $openLpItem->openlp_search_title);
        $this->assertSame($song->id, $openLpItem->song_id, 'The order of service owns the song link.');
        $this->assertSame(ChurchServiceItemSource::OpenLp, $openLpItem->source);
        $this->assertSame(1, $openLpItem->position, 'Order-of-service items keep their planned position.');

        $this->assertSame(
            $log->processing_id,
            $openLpItem->livestream_processing_id,
            'The detected run still attaches its provenance to the matched item.',
        );
        $this->assertSame($sections[0]->id, $openLpItem->livestream_service_section_id);
        $this->assertSame($openLpItem->id, $sections[0]->fresh()->church_service_item_id);

        $this->assertFalse($churchService->fresh()->needs_review);
    }

    #[Test]
    public function test_livestream_keeps_an_undetected_planned_song_beside_its_anchor(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-03-23',
            'service' => SermonService::Morning->value,
            'source' => 'openlp',
            'needs_review' => false,
        ]);

        foreach (['First Planned Song', 'Second Planned Song'] as $index => $title) {
            ChurchServiceItem::factory()->create([
                'church_service_id' => $churchService->id,
                'position' => $index + 1,
                'type' => 'songs',
                'title' => $title,
                'source' => ChurchServiceItemSource::OpenLp->value,
            ]);
        }

        $log = $this->createProcessingLog('2026-03-23', SermonService::Morning);
        $this->createSections($log, [
            ['type' => ServiceSectionType::Song, 'title' => 'First Planned Song', 'confidence' => 0.9],
            ['type' => ServiceSectionType::Sermon, 'title' => 'An Undetected Sermon', 'confidence' => 0.9],
        ]);

        $this->service->project($log);

        $items = $churchService->fresh()->items()->orderBy('position')->get();

        // The run anchors only the first song, so the second planned song has a
        // preceding anchor but no following one. With no evidence about where it
        // sat relative to the sermon, it stays adjacent to the anchor it followed
        // in the plan rather than being flung to the end of the list.
        $this->assertCount(3, $items, 'No order-of-service item may be dropped by a detected run.');
        $this->assertSame('First Planned Song', $items[0]->title);
        $this->assertSame('Second Planned Song', $items[1]->title);
        $this->assertSame(ChurchServiceItemSource::OpenLp, $items[1]->source);
        $this->assertSame('An Undetected Sermon', $items[2]->title);
        $this->assertSame(ChurchServiceItemSource::Livestream, $items[2]->source);
    }

    #[Test]
    public function test_merging_a_livestream_run_loses_no_public_song_usage(): void
    {
        $song = Song::factory()->create(['title' => 'Amazing Grace']);

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
            'title' => 'Amazing Grace',
            'song_id' => $song->id,
            'source' => ChurchServiceItemSource::OpenLp->value,
        ]);

        $usageBefore = $this->publicSongUsageItemIds($song);
        $this->assertNotEmpty($usageBefore, 'The fixture must qualify before the merge to be a meaningful gate.');

        // A completed run is the case Phase 6.1 governs: once one exists, an
        // order-of-service song stays publicly listed only while a section
        // confirms the match. Pin the status rather than inheriting the
        // factory's random one, or this gate passes or fails by luck.
        $log = $this->createProcessingLog('2026-03-23', SermonService::Morning, ProcessingStatus::Completed);
        $this->createSections($log, [
            [
                'type' => ServiceSectionType::Song,
                'title' => 'Amazing Grace',
                'confidence' => 0.9,
                'song_match_type' => ServiceSectionSongMatchType::Confirmed,
            ],
        ]);

        $this->service->project($log);

        $this->assertSame(
            $usageBefore,
            $this->publicSongUsageItemIds($song),
            'Projecting a livestream run onto an order-of-service must not drop qualifying song usage.',
        );
    }

    #[Test]
    public function test_reprojecting_after_song_matching_anchors_on_the_resolved_song(): void
    {
        $song = Song::factory()->create(['title' => 'Great Is Thy Faithfulness']);

        $churchService = ChurchService::factory()->create([
            'date' => '2026-03-23',
            'service' => SermonService::Morning->value,
            'source' => 'openlp',
            'needs_review' => false,
        ]);

        $planned = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'title' => 'Great Is Thy Faithfulness, O God My Father',
            'song_id' => $song->id,
            'source' => ChurchServiceItemSource::OpenLp->value,
        ]);

        // First pass: song matching has not run, so the run only knows the text
        // it heard, which does not match the planned title.
        $log = $this->createProcessingLog('2026-03-23', SermonService::Morning);
        [$section] = $this->createSections($log, [
            ['type' => ServiceSectionType::Song, 'title' => 'Great is thy faithfulness o God', 'confidence' => 0.9],
        ]);

        $this->service->project($log);

        $this->assertCount(2, $churchService->fresh()->items()->get(), 'Without a resolved song the run cannot anchor on the plan.');

        // Song matching then resolves the catalogue song for that section.
        $section->refresh()->forceFill([
            'song_match_type' => ServiceSectionSongMatchType::Confirmed,
            'metadata' => [...$section->metadata?->toArray() ?? [], 'song_id' => $song->id],
        ])->save();

        $this->service->project($log);

        $items = $churchService->fresh()->items()->orderBy('position')->get();

        $this->assertCount(1, $items, 'The resolved song anchors the run to the planned item, collapsing the duplicate.');
        $this->assertSame($planned->id, $items[0]->id);
        $this->assertSame('Great Is Thy Faithfulness, O God My Father', $items[0]->title, 'The order of service still owns the title.');
        $this->assertSame(ChurchServiceItemSource::OpenLp, $items[0]->source);
    }

    #[Test]
    public function test_projecting_the_same_run_twice_is_idempotent(): void
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
            'title' => 'Opening Song',
            'source' => ChurchServiceItemSource::OpenLp->value,
        ]);

        $log = $this->createProcessingLog('2026-03-23', SermonService::Morning);
        $this->createSections($log, [
            ['type' => ServiceSectionType::Song, 'title' => 'Opening Song', 'confidence' => 0.9],
            ['type' => ServiceSectionType::Sermon, 'title' => 'The faithfulness of God', 'confidence' => 0.9],
        ]);

        $this->service->project($log);
        $firstPass = $churchService->fresh()->items()->orderBy('position')->pluck('title')->all();

        $this->service->project($log);
        $secondPass = $churchService->fresh()->items()->orderBy('position')->pluck('title')->all();

        $this->assertSame(['Opening Song', 'The faithfulness of God'], $firstPass);
        $this->assertSame($firstPass, $secondPass, 'A second projection of the same run must not duplicate or reorder items.');
    }

    /**
     * @return list<int>
     */
    private function publicSongUsageItemIds(Song $song): array
    {
        $ids = app(PublicSongUsageService::class)
            ->usageHistoryForSong($song)
            ->pluck('id')
            ->all();

        sort($ids);

        return array_values(array_map('intval', $ids));
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
    public function test_links_processing_log_to_existing_service_when_merged(): void
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

        $this->assertTrue($result['projected']);

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

    private function createProcessingLog(
        string $date,
        SermonService $service,
        ?ProcessingStatus $status = null,
    ): MediaProcessingLog {
        return MediaProcessingLog::factory()->livestream()->create(array_filter([
            'extracted_date' => $date,
            'extracted_service' => $service->value,
            'status' => $status,
        ]));
    }

    /**
     * @param  array<int, array{type: ServiceSectionType, title: string, confidence: float, song_match_type?: ServiceSectionSongMatchType}>  $sectionData
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
                'song_match_type' => $data['song_match_type'] ?? null,
            ]);
        }

        return $sections;
    }
}
