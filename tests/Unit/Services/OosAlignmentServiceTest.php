<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\SermonService;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\OosAlignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OosAlignmentServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_matches_song_sections_by_title_instead_of_position(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-06-07',
            'service' => SermonService::MORNING->value,
        ]);

        $firstSong = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'title' => 'Song One',
        ]);

        $secondSong = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 2,
            'type' => 'songs',
            'title' => 'Song Two',
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $churchService->id,
        ]);

        $firstDetected = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::SONG->value,
            'section_order' => 1,
            'title' => 'Song Two',
            'confidence' => 0.5,
            'metadata' => [
                'confidence_level' => 'low',
                'classification_mode' => 'audio_only',
            ],
        ]);

        $secondDetected = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::SONG->value,
            'section_order' => 2,
            'title' => 'Song One',
            'confidence' => 0.5,
            'metadata' => [
                'confidence_level' => 'low',
                'classification_mode' => 'audio_only',
            ],
        ]);

        $result = app(OosAlignmentService::class)->alignForProcessingLog($processingLog, $churchService);

        $firstDetected->refresh();
        $secondDetected->refresh();

        $this->assertTrue($result['aligned']);
        $this->assertSame([], $result['review_triggers']);
        $this->assertSame($secondSong->id, $firstDetected->church_service_item_id);
        $this->assertSame($firstSong->id, $secondDetected->church_service_item_id);
        $this->assertSame('Song Two', $firstDetected->title);
        $this->assertSame('Song One', $secondDetected->title);
        $this->assertSame('confirmed', $firstDetected->metadata['oos_alignment']['song_match_type'] ?? null);
        $this->assertSame('confirmed', $secondDetected->metadata['oos_alignment']['song_match_type'] ?? null);
        $this->assertSame('high', $firstDetected->metadata['confidence_level']);
        $this->assertSame('high', $secondDetected->metadata['confidence_level']);
    }

    #[Test]
    public function it_enriches_bible_readings_and_raises_confidence_when_structure_agrees(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-06-14',
            'service' => SermonService::MORNING->value,
        ]);

        $prayer = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'custom',
            'title' => 'Opening Prayer',
        ]);

        $reading = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 2,
            'type' => 'bibles',
            'title' => 'Luke 15:1-32',
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $churchService->id,
        ]);

        $prayerSection = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::PRAYER->value,
            'section_order' => 1,
            'title' => null,
            'confidence' => 0.5,
            'metadata' => [
                'confidence_level' => 'low',
                'classification_mode' => 'ai_transcript',
            ],
        ]);

        $readingSection = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::BIBLE_READING->value,
            'section_order' => 2,
            'title' => null,
            'confidence' => 0.5,
            'metadata' => [
                'confidence_level' => 'low',
                'classification_mode' => 'ai_transcript',
            ],
        ]);

        $result = app(OosAlignmentService::class)->alignForProcessingLog($processingLog, $churchService);

        $prayerSection->refresh();
        $readingSection->refresh();

        $this->assertSame([], $result['review_triggers']);
        $this->assertSame($prayer->id, $prayerSection->church_service_item_id);
        $this->assertSame('Opening Prayer', $prayerSection->title);
        $this->assertSame('high', $prayerSection->metadata['confidence_level']);
        $this->assertSame($reading->id, $readingSection->church_service_item_id);
        $this->assertSame('Luke 15:1-32', $readingSection->metadata['reading_reference']);
        $this->assertSame('high', $readingSection->metadata['confidence_level']);
    }

    #[Test]
    public function it_does_not_force_structural_matches_to_high_confidence_when_the_base_confidence_is_very_low(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-06-18',
            'service' => SermonService::MORNING->value,
        ]);

        $prayer = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'custom',
            'title' => 'Opening Prayer',
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $churchService->id,
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::PRAYER->value,
            'section_order' => 1,
            'title' => null,
            'confidence' => 0.1,
            'metadata' => [
                'confidence_level' => 'none',
                'classification_mode' => 'ai_transcript',
            ],
        ]);

        app(OosAlignmentService::class)->alignForProcessingLog($processingLog, $churchService);

        $section->refresh();

        $this->assertSame($prayer->id, $section->church_service_item_id);
        $this->assertLessThan(0.90, $section->confidence);
        $this->assertSame('none', $section->metadata['confidence_level']);
    }

    #[Test]
    public function it_applies_a_low_confidence_oos_label_to_titleless_song_sections_and_keeps_them_under_review(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-06-21',
            'service' => SermonService::MORNING->value,
        ]);

        $song = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'title' => 'Known Song',
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $churchService->id,
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::SONG->value,
            'section_order' => 1,
            'title' => null,
            'confidence' => 0.5,
            'metadata' => [
                'confidence_level' => 'low',
                'classification_mode' => 'audio_only',
            ],
        ]);

        $result = app(OosAlignmentService::class)->alignForProcessingLog($processingLog, $churchService);

        $section->refresh();
        $churchService->refresh();

        $this->assertContains('unmatched_song_sections', $result['review_triggers']);
        $this->assertTrue($section->needs_manual_review);
        $this->assertSame($song->id, $section->church_service_item_id);
        $this->assertSame('Known Song', $section->title);
        $this->assertSame('song_alignment_inferred', $section->metadata['review_reason']);
        $this->assertContains('song_alignment_inferred', $section->metadata['review_flags']);
        $this->assertContains('unmatched_song_section', $section->metadata['review_flags']);
        $this->assertSame('inferred', $section->metadata['oos_alignment']['song_match_type'] ?? null);
        $this->assertNull($section->metadata['song_id'] ?? null);
        $this->assertTrue($churchService->needs_review);
    }

    #[Test]
    public function it_marks_unmatched_detected_song_sections_with_an_explicit_unmatched_state(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-06-25',
            'service' => SermonService::MORNING->value,
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'title' => 'Known Song',
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $churchService->id,
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::SONG->value,
            'section_order' => 1,
            'title' => 'Completely Different Song',
            'confidence' => 0.5,
            'metadata' => [
                'confidence_level' => 'low',
                'classification_mode' => 'audio_only',
            ],
        ]);

        $result = app(OosAlignmentService::class)->alignForProcessingLog($processingLog, $churchService);

        $section->refresh();

        $this->assertContains('unmatched_song_sections', $result['review_triggers']);
        $this->assertTrue($section->needs_manual_review);
        $this->assertNull($section->church_service_item_id);
        $this->assertSame('unmatched', $section->metadata['oos_alignment']['song_match_type'] ?? null);
    }

    #[Test]
    public function it_flags_structure_mismatches_and_late_alignment_changes_for_review(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-06-28',
            'service' => SermonService::MORNING->value,
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'custom',
            'title' => 'Opening Prayer',
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $churchService->id,
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::NOTICES->value,
            'section_order' => 1,
            'title' => 'Church Notices',
            'confidence' => 0.5,
            'metadata' => [
                'confidence_level' => 'low',
                'classification_mode' => 'ai_transcript',
            ],
        ]);

        $result = app(OosAlignmentService::class)->alignForProcessingLog(
            $processingLog,
            $churchService,
            lateArrival: true
        );

        $section->refresh();
        $churchService->refresh();

        $this->assertContains('oos_structure_mismatch', $result['review_triggers']);
        $this->assertContains('late_oos_alignment_changed', $result['review_triggers']);
        $this->assertTrue($section->needs_manual_review);
        $this->assertSame('oos_structure_mismatch', $section->metadata['review_reason']);
        $this->assertTrue($churchService->needs_review);
    }

    #[Test]
    public function it_reclassifies_an_other_section_to_childrens_talk_when_the_oos_item_signals_it(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-07-20',
            'service' => SermonService::MORNING->value,
        ]);

        $item = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'custom',
            'title' => "Children's Talk",
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $churchService->id,
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::OTHER->value,
            'section_order' => 1,
            'title' => null,
            'confidence' => 0.5,
            'metadata' => [
                'confidence_level' => 'low',
                'classification_mode' => 'audio_only',
            ],
        ]);

        $result = app(OosAlignmentService::class)->alignForProcessingLog($processingLog, $churchService);

        $section->refresh();

        $this->assertTrue($result['aligned']);
        $this->assertSame([], $result['review_triggers']);
        $this->assertSame(ServiceSectionType::CHILDRENS_TALK, $section->section_type);
        $this->assertSame($item->id, $section->church_service_item_id);
        $this->assertSame("Children's Talk", $section->title);
        $this->assertSame(ServiceSectionType::OTHER->value, $section->metadata['oos_alignment']['reclassified_from']);
        $this->assertSame('oos_alignment', $section->metadata['oos_alignment']['reclassified_by']);
    }

    #[Test]
    public function it_does_not_reclassify_an_other_section_when_the_oos_item_also_resolves_to_other(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-07-21',
            'service' => SermonService::MORNING->value,
        ]);

        $item = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'liturgy',
            'title' => 'Responsive Reading',
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $churchService->id,
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::OTHER->value,
            'section_order' => 1,
            'title' => null,
            'confidence' => 0.5,
            'metadata' => [
                'confidence_level' => 'low',
                'classification_mode' => 'audio_only',
            ],
        ]);

        app(OosAlignmentService::class)->alignForProcessingLog($processingLog, $churchService);

        $section->refresh();

        $this->assertSame(ServiceSectionType::OTHER, $section->section_type);
        $this->assertSame($item->id, $section->church_service_item_id);
        $this->assertArrayNotHasKey('reclassified_from', $section->metadata['oos_alignment'] ?? []);
    }

    #[Test]
    public function it_only_uses_title_based_type_inference_for_custom_items(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-07-03',
            'service' => SermonService::MORNING->value,
        ]);

        $item = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'liturgy',
            'title' => 'Welcome to the Family',
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $churchService->id,
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::OTHER->value,
            'section_order' => 1,
            'title' => null,
            'confidence' => 0.5,
            'metadata' => [
                'confidence_level' => 'low',
                'classification_mode' => 'ai_transcript',
            ],
        ]);

        $result = app(OosAlignmentService::class)->alignForProcessingLog($processingLog, $churchService);

        $section->refresh();

        $this->assertSame([], $result['review_triggers']);
        $this->assertSame($item->id, $section->church_service_item_id);
        $this->assertSame(ServiceSectionType::OTHER, $section->section_type);
    }

    #[Test]
    public function it_returns_aligned_false_when_the_church_service_has_no_items(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-07-06',
            'service' => SermonService::MORNING->value,
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $churchService->id,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::SONG->value,
            'section_order' => 1,
            'title' => 'A Song',
            'confidence' => 0.5,
            'metadata' => ['confidence_level' => 'low', 'classification_mode' => 'audio_only'],
        ]);

        $result = app(OosAlignmentService::class)->alignForProcessingLog($processingLog, $churchService);

        $this->assertFalse($result['aligned']);
        $this->assertSame([], $result['review_triggers']);
    }

    #[Test]
    public function it_aligns_song_sections_successfully_even_when_the_song_catalog_entry_is_missing(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-07-09',
            'service' => SermonService::MORNING->value,
        ]);

        // Item exists in OoS but has no Song record linked
        $item = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'title' => 'Unlisted Hymn',
            'song_id' => null,
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $churchService->id,
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::SONG->value,
            'section_order' => 1,
            'title' => 'Unlisted Hymn',
            'confidence' => 0.5,
            'metadata' => ['confidence_level' => 'low', 'classification_mode' => 'audio_only'],
        ]);

        $result = app(OosAlignmentService::class)->alignForProcessingLog($processingLog, $churchService);

        $section->refresh();

        // Alignment still completes; the section is linked to the item
        $this->assertTrue($result['aligned']);
        $this->assertSame($item->id, $section->church_service_item_id);
        // song_id in metadata is null when no Song catalog entry is linked
        $this->assertNull($section->metadata['song_id'] ?? null);
    }

    #[Test]
    public function it_clears_stale_alignment_review_triggers_when_the_service_now_aligns_cleanly(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-07-05',
            'service' => SermonService::MORNING->value,
            'needs_review' => true,
            'import_metadata' => [
                'review_triggers' => ['unmatched_song_sections'],
            ],
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'title' => 'Known Song',
            'openlp_search_title' => 'known song',
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $churchService->id,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::SONG->value,
            'section_order' => 1,
            'title' => 'Known Song',
            'confidence' => 0.5,
            'metadata' => [
                'confidence_level' => 'low',
                'classification_mode' => 'audio_only',
            ],
        ]);

        $result = app(OosAlignmentService::class)->alignForProcessingLog($processingLog, $churchService);

        $churchService->refresh();

        $this->assertSame([], $result['review_triggers']);
        $this->assertFalse($churchService->needs_review);
        $this->assertArrayNotHasKey('review_triggers', $churchService->import_metadata ?? []);
    }

    #[Test]
    public function it_preserves_existing_import_review_flags_when_alignment_triggers_clear(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-07-12',
            'service' => SermonService::MORNING->value,
            'source' => 'email',
            'needs_review' => true,
            'import_metadata' => [
                'confidence_score' => 0.82,
                'review_triggers' => ['unmatched_song_sections'],
            ],
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'custom',
            'title' => 'Opening Prayer',
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $churchService->id,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::PRAYER->value,
            'section_order' => 1,
            'title' => null,
            'confidence' => 0.5,
            'metadata' => [
                'confidence_level' => 'low',
                'classification_mode' => 'ai_transcript',
            ],
        ]);

        $result = app(OosAlignmentService::class)->alignForProcessingLog($processingLog, $churchService);

        $churchService->refresh();

        $this->assertSame([], $result['review_triggers']);
        $this->assertTrue($churchService->needs_review);
        $this->assertSame(0.82, $churchService->import_metadata['confidence_score']);
        $this->assertArrayNotHasKey('review_triggers', $churchService->import_metadata ?? []);
    }

    #[Test]
    public function it_does_not_clear_review_reopened_for_an_outstanding_canonical_conflict(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-07-19',
            'service' => SermonService::MORNING->value,
            'source' => 'manual',
            'needs_review' => true,
            'import_metadata' => [
                'manual_review' => [
                    'reviewed_at' => now()->subDays(2)->toIso8601String(),
                    'reviewed_by_user_id' => 1,
                    'reopened_at' => now()->subDay()->toIso8601String(),
                    'reopened_by_source' => 'openlp',
                ],
                'canonical_conflict' => [
                    'detected_at' => now()->subDay()->toIso8601String(),
                    'incoming_source' => 'openlp',
                    'review_reopened' => true,
                    'reviewed_previously' => true,
                    'canonical_changed' => true,
                    'changes' => [['type' => 'updated_item']],
                    'conflicts' => [],
                ],
            ],
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'custom',
            'title' => 'Opening Prayer',
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $churchService->id,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::PRAYER->value,
            'section_order' => 1,
            'title' => null,
            'confidence' => 0.5,
            'metadata' => [
                'confidence_level' => 'low',
                'classification_mode' => 'ai_transcript',
            ],
        ]);

        $result = app(OosAlignmentService::class)->alignForProcessingLog($processingLog, $churchService);

        $churchService->refresh();

        $this->assertSame([], $result['review_triggers']);
        $this->assertTrue($churchService->needs_review);
        $this->assertSame('openlp', $churchService->import_metadata['canonical_conflict']['incoming_source'] ?? null);
    }

    #[Test]
    public function it_reclassifies_a_speech_section_to_childrens_talk_when_a_post_song_presentation_is_in_the_oos(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-11-23',
            'service' => SermonService::MORNING->value,
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'title' => 'How Great Thou Art',
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 2,
            'type' => 'presentations',
            'title' => "Children's Talk",
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $churchService->id,
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::OTHER->value,
            'section_order' => 2,
            'title' => null,
            'confidence' => 0.5,
            'metadata' => [
                'confidence_level' => 'low',
                'classification_mode' => 'audio_only',
            ],
        ]);

        app(OosAlignmentService::class)->alignForProcessingLog($processingLog, $churchService);

        $section->refresh();

        $this->assertSame(ServiceSectionType::CHILDRENS_TALK->value, $section->section_type->value);
        $this->assertSame(ServiceSectionType::OTHER->value, $section->metadata['oos_alignment']['reclassified_from'] ?? null);
        $this->assertSame('oos_alignment', $section->metadata['oos_alignment']['reclassified_by'] ?? null);
        $this->assertNotContains('ambiguous_childrens_talk', $section->metadata['review_flags'] ?? []);
        $this->assertFalse($section->needs_manual_review);
    }

    #[Test]
    public function it_classifies_a_pre_first_song_presentation_as_notices_not_childrens_talk(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-11-24',
            'service' => SermonService::MORNING->value,
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'presentations',
            'title' => 'Notices',
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 2,
            'type' => 'songs',
            'title' => 'To God Be The Glory',
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $churchService->id,
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::OTHER->value,
            'section_order' => 1,
            'title' => null,
            'confidence' => 0.5,
            'metadata' => [
                'confidence_level' => 'low',
                'classification_mode' => 'audio_only',
            ],
        ]);

        app(OosAlignmentService::class)->alignForProcessingLog($processingLog, $churchService);

        $section->refresh();

        $this->assertSame(ServiceSectionType::NOTICES->value, $section->section_type->value);
        $this->assertNotSame(ServiceSectionType::CHILDRENS_TALK->value, $section->section_type->value);
    }

    #[Test]
    public function it_flags_for_review_when_multiple_post_song_presentations_exist(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-11-25',
            'service' => SermonService::MORNING->value,
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'title' => 'Great Is Thy Faithfulness',
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 2,
            'type' => 'presentations',
            'title' => "Children's Talk Part 1",
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 3,
            'type' => 'presentations',
            'title' => "Children's Talk Part 2",
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $churchService->id,
        ]);

        $firstSection = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::OTHER->value,
            'section_order' => 2,
            'title' => null,
            'confidence' => 0.5,
            'metadata' => [
                'confidence_level' => 'low',
                'classification_mode' => 'audio_only',
            ],
        ]);

        $secondSection = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::OTHER->value,
            'section_order' => 3,
            'title' => null,
            'confidence' => 0.5,
            'metadata' => [
                'confidence_level' => 'low',
                'classification_mode' => 'audio_only',
            ],
        ]);

        app(OosAlignmentService::class)->alignForProcessingLog($processingLog, $churchService);

        $firstSection->refresh();
        $secondSection->refresh();

        $this->assertSame(ServiceSectionType::CHILDRENS_TALK->value, $firstSection->section_type->value);
        $this->assertSame(ServiceSectionType::CHILDRENS_TALK->value, $secondSection->section_type->value);
        $this->assertTrue($firstSection->needs_manual_review);
        $this->assertTrue($secondSection->needs_manual_review);
        $this->assertContains('ambiguous_childrens_talk', $firstSection->metadata['review_flags'] ?? []);
        $this->assertContains('ambiguous_childrens_talk', $secondSection->metadata['review_flags'] ?? []);
        $this->assertSame('ambiguous_childrens_talk', $firstSection->metadata['review_reason'] ?? null);
        $this->assertSame('ambiguous_childrens_talk', $secondSection->metadata['review_reason'] ?? null);
    }
}
