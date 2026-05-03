<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SermonService;
use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Services\OosAlignmentService;
use App\Support\ServiceSectionConfidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SongSectionAlignerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Greedy preservation: the first item-section pairing is locked in even if a later
     * section would score equally or higher. This preserves the OoS presentation order
     * rather than optimising globally.
     */
    #[Test]
    public function it_preserves_greedy_first_match_and_does_not_reassign_a_locked_section(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-10-05',
            'service' => SermonService::Morning->value,
        ]);

        // Two OoS items with identical titles — the first item wins the first available section.
        $firstItem = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'title' => 'Amazing Grace',
        ]);

        $secondItem = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 2,
            'type' => 'songs',
            'title' => 'Amazing Grace',
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $churchService->id,
        ]);

        $firstSection = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::SONG->value,
            'section_order' => 1,
            'title' => 'Amazing Grace',
            'confidence' => 0.5,
            'metadata' => ['classification_mode' => 'ai_transcript'],
        ]);

        $secondSection = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::SONG->value,
            'section_order' => 2,
            'title' => 'Amazing Grace',
            'confidence' => 0.5,
            'metadata' => ['classification_mode' => 'ai_transcript'],
        ]);

        $result = app(OosAlignmentService::class)->alignForProcessingLog($processingLog, $churchService);

        $firstSection->refresh();
        $secondSection->refresh();

        // Both sections match — first item takes the first section, second item takes the second.
        $this->assertSame(2, $result['matched_song_sections']);
        $this->assertSame($firstItem->id, $firstSection->church_service_item_id);
        $this->assertSame($secondItem->id, $secondSection->church_service_item_id);
    }

    /**
     * Confirmed matches write song_id and meet the high-confidence threshold.
     * The match type must be 'confirmed' in metadata.
     */
    #[Test]
    public function it_writes_song_id_and_high_confidence_on_confirmed_match(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-10-12',
            'service' => SermonService::Morning->value,
        ]);

        $song = Song::factory()->create(['title' => 'Be Thou My Vision']);

        $item = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'title' => 'Be Thou My Vision',
            'song_id' => $song->id,
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $churchService->id,
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::SONG->value,
            'section_order' => 1,
            'title' => 'Be Thou My Vision',
            'confidence' => 0.5,
            'metadata' => ['classification_mode' => 'ai_transcript'],
        ]);

        app(OosAlignmentService::class)->alignForProcessingLog($processingLog, $churchService);

        $section->refresh();

        $this->assertSame(ServiceSectionSongMatchType::CONFIRMED, $section->song_match_type);
        $this->assertArrayNotHasKey('song_match_type', $section->metadata['oos_alignment'] ?? []);
        $this->assertSame($song->id, $section->metadata['song_id'] ?? null);
        $this->assertGreaterThanOrEqual(ServiceSectionConfidence::HIGH_THRESHOLD, $section->confidence);
    }

    /**
     * Inferred matches (positional fallback) must not write song_id and must stay
     * below the 0.84 confidence cap.
     */
    #[Test]
    public function it_does_not_write_song_id_on_inferred_match_and_caps_confidence_below_high(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-10-19',
            'service' => SermonService::Morning->value,
        ]);

        $song = Song::factory()->create(['title' => 'How Great Thou Art']);

        // Item has a title that will NOT match the section (section has no title),
        // forcing positional inference.
        $item = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'title' => 'How Great Thou Art',
            'openlp_search_title' => 'how great thou art',
            'song_id' => $song->id,
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $churchService->id,
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::SONG->value,
            'section_order' => 1,
            'title' => null,
            'confidence' => 0.5,
            'metadata' => ['classification_mode' => 'audio_only'],
        ]);

        app(OosAlignmentService::class)->alignForProcessingLog($processingLog, $churchService);

        $section->refresh();

        $this->assertSame(ServiceSectionSongMatchType::INFERRED, $section->song_match_type);
        $this->assertArrayNotHasKey('song_match_type', $section->metadata['oos_alignment'] ?? []);
        $this->assertNull($section->metadata['song_id'] ?? null);
        $this->assertLessThanOrEqual(0.84, $section->confidence);
        $this->assertTrue($section->needs_manual_review);
        $this->assertContains('song_alignment_inferred', $section->metadata['review_flags'] ?? []);
    }

    /**
     * When a song section has no title evidence, no confirmed match is produced
     * (score is 0.0 with no section-side candidates), so it is counted as unmatched
     * and the unmatched_song_sections review trigger is raised.
     */
    #[Test]
    public function it_counts_unmatched_song_section_when_no_candidate_exists(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-10-26',
            'service' => SermonService::Morning->value,
        ]);

        // Section has no title candidates, so songMatchScore returns 0.0 against
        // any item — no confirmed match can be produced.
        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'title' => 'Untitled Song',
            'openlp_search_title' => null,
            'source_title' => null,
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $churchService->id,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::SONG->value,
            'section_order' => 1,
            'title' => null,
            'confidence' => 0.9,
            'metadata' => ['classification_mode' => 'ai_transcript'],
        ]);

        $result = app(OosAlignmentService::class)->alignForProcessingLog($processingLog, $churchService);

        $this->assertSame(0, $result['matched_song_sections']);
        $this->assertSame(1, $result['unmatched_song_sections']);
        $this->assertContains('unmatched_song_sections', $result['review_triggers']);
    }

    /**
     * A section whose metadata.song_id was set in a prior pass is cleared at baseline
     * restoration, so the match falls through to title scoring. A title match still
     * produces a confirmed result.
     */
    #[Test]
    public function it_matches_confirmed_when_titles_match_after_baseline_clears_song_id(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-11-02',
            'service' => SermonService::Morning->value,
        ]);

        $song = Song::factory()->create(['title' => 'O Praise the Name']);

        $item = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'title' => 'O Praise the Name',
            'song_id' => $song->id,
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $churchService->id,
        ]);

        // Section has song_id in metadata from a prior pass; it will be cleared at
        // baseline restore, but the title will still produce a confirmed match.
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::SONG->value,
            'section_order' => 1,
            'title' => 'O Praise the Name',
            'confidence' => 0.5,
            'metadata' => [
                'classification_mode' => 'ai_transcript',
                'song_id' => $song->id,
            ],
        ]);

        $result = app(OosAlignmentService::class)->alignForProcessingLog($processingLog, $churchService);

        $section->refresh();

        $this->assertSame(1, $result['matched_song_sections']);
        $this->assertSame($item->id, $section->church_service_item_id);
        $this->assertSame(ServiceSectionSongMatchType::CONFIRMED, $section->song_match_type);
        $this->assertArrayNotHasKey('song_match_type', $section->metadata['oos_alignment'] ?? []);
        // song_id is re-written from item.song_id after a confirmed match
        $this->assertSame($song->id, $section->metadata['song_id'] ?? null);
    }

    /**
     * A song_title_hint in section metadata (written by SongTitleHintExtractor) must
     * contribute to the candidate score in songCandidatesFromSection() and produce a
     * confirmed match when it matches an OoS item's title — even when section.title is null.
     */
    #[Test]
    public function it_matches_song_section_via_song_title_hint_when_title_is_null(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-10-26',
            'service' => SermonService::Morning->value,
        ]);

        $song = Song::factory()->create(['title' => 'Though the Nations Rage']);

        $item = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'title' => 'Though the Nations Rage',
            'song_id' => $song->id,
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $churchService->id,
        ]);

        // Section has no title but carries a song_title_hint from SongTitleHintExtractor.
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::SONG->value,
            'section_order' => 1,
            'title' => null,
            'confidence' => 0.5,
            'metadata' => [
                'classification_mode' => 'audio_only',
                'song_title_hint' => 'Though the Nations Rage',
            ],
        ]);

        app(OosAlignmentService::class)->alignForProcessingLog($processingLog, $churchService);

        $section->refresh();

        $this->assertSame(ServiceSectionSongMatchType::CONFIRMED, $section->song_match_type);
        $this->assertSame($item->id, $section->church_service_item_id);
        $this->assertSame($song->id, $section->metadata['song_id'] ?? null);
        $this->assertGreaterThanOrEqual(ServiceSectionConfidence::HIGH_THRESHOLD, $section->confidence);
    }
}
