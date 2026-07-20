<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Data\ServiceSectionMetadata;
use App\Enums\SermonService;
use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\User;
use App\Services\ChurchService\OosAlignmentService;
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
            'service' => SermonService::Morning->value,
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
            'section_type' => ServiceSectionType::Song->value,
            'section_order' => 1,
            'title' => 'Song Two',
            'confidence' => 0.9,
            'metadata' => [
                'confidence_level' => 'high',
                'classification_mode' => 'audio_only',
            ],
        ]);

        $secondDetected = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::Song->value,
            'section_order' => 2,
            'title' => 'Song One',
            'confidence' => 0.9,
            'metadata' => [
                'confidence_level' => 'high',
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
        $this->assertSame(ServiceSectionSongMatchType::Confirmed, $firstDetected->song_match_type);
        $this->assertSame(ServiceSectionSongMatchType::Confirmed, $secondDetected->song_match_type);
        $this->assertArrayNotHasKey('song_match_type', $firstDetected->metadata['oos_alignment'] ?? []);
        $this->assertArrayNotHasKey('song_match_type', $secondDetected->metadata['oos_alignment'] ?? []);
        $this->assertSame('high', $firstDetected->metadata['confidence_level']);
        $this->assertSame('high', $secondDetected->metadata['confidence_level']);
    }

    #[Test]
    public function it_enriches_bible_readings_and_raises_confidence_when_structure_agrees(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-06-14',
            'service' => SermonService::Morning->value,
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
            'section_type' => ServiceSectionType::Prayer->value,
            'section_order' => 1,
            'title' => null,
            'confidence' => 0.9,
            'metadata' => [
                'confidence_level' => 'high',
                'classification_mode' => 'ai_transcript',
            ],
        ]);

        $readingSection = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::BibleReading->value,
            'section_order' => 2,
            'title' => null,
            'confidence' => 0.9,
            'metadata' => [
                'confidence_level' => 'high',
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
            'service' => SermonService::Morning->value,
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
            'section_type' => ServiceSectionType::Prayer->value,
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
            'service' => SermonService::Morning->value,
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
            'section_type' => ServiceSectionType::Song->value,
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
        $this->assertSame(ServiceSectionSongMatchType::Inferred, $section->song_match_type);
        $this->assertArrayNotHasKey('song_match_type', $section->metadata['oos_alignment'] ?? []);
        $this->assertNull($section->metadata['song_id'] ?? null);
        $this->assertTrue($churchService->needs_review);
    }

    #[Test]
    public function it_marks_unmatched_detected_song_sections_with_an_explicit_unmatched_state(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-06-25',
            'service' => SermonService::Morning->value,
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
            'section_type' => ServiceSectionType::Song->value,
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
        $this->assertSame(ServiceSectionSongMatchType::Unmatched, $section->song_match_type);
        $this->assertArrayNotHasKey('song_match_type', $section->metadata['oos_alignment'] ?? []);
    }

    #[Test]
    public function it_flags_structure_mismatches_and_late_alignment_changes_for_review(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-06-28',
            'service' => SermonService::Morning->value,
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
            'section_type' => ServiceSectionType::Notices->value,
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
            'service' => SermonService::Morning->value,
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
            'section_type' => ServiceSectionType::Other->value,
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
        $this->assertSame(ServiceSectionType::ChildrensTalk, $section->section_type);
        $this->assertSame($item->id, $section->church_service_item_id);
        $this->assertSame("Children's Talk", $section->title);
        $this->assertSame(ServiceSectionType::Other->value, $section->metadata['oos_alignment']['reclassified_from']);
        $this->assertSame('oos_alignment', $section->metadata['oos_alignment']['reclassified_by']);
    }

    #[Test]
    public function it_does_not_reclassify_an_other_section_when_the_oos_item_also_resolves_to_other(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-07-21',
            'service' => SermonService::Morning->value,
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
            'section_type' => ServiceSectionType::Other->value,
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

        $this->assertSame(ServiceSectionType::Other, $section->section_type);
        $this->assertSame($item->id, $section->church_service_item_id);
        $this->assertArrayNotHasKey('reclassified_from', $section->metadata['oos_alignment'] ?? []);
    }

    #[Test]
    public function it_only_uses_title_based_type_inference_for_custom_items(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-07-03',
            'service' => SermonService::Morning->value,
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
            'section_type' => ServiceSectionType::Other->value,
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
        $this->assertSame(ServiceSectionType::Other, $section->section_type);
    }

    #[Test]
    public function it_returns_aligned_false_when_the_church_service_has_no_items(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-07-06',
            'service' => SermonService::Morning->value,
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $churchService->id,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::Song->value,
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
            'service' => SermonService::Morning->value,
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
            'section_type' => ServiceSectionType::Song->value,
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
            'service' => SermonService::Morning->value,
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
            'section_type' => ServiceSectionType::Song->value,
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
            'service' => SermonService::Morning->value,
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
            'section_type' => ServiceSectionType::Prayer->value,
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
    public function it_does_not_clear_a_canonical_change_review_reason(): void
    {
        $previousReviewer = User::factory()->create(['is_admin' => true]);
        $churchService = ChurchService::factory()->create([
            'date' => '2026-07-19',
            'service' => SermonService::Morning->value,
            'source' => 'manual',
            'needs_review' => true,
            'review_reason' => 'Service items changed after manual review.',
            'import_metadata' => [
                'manual_review' => [
                    'reviewed_at' => now()->subDays(2)->toIso8601String(),
                    'reviewed_by_user_id' => $previousReviewer->id,
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
            'section_type' => ServiceSectionType::Prayer->value,
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
        $this->assertSame('Service items changed after manual review.', $churchService->review_reason);
    }

    #[Test]
    public function it_reclassifies_a_speech_section_to_childrens_talk_when_a_post_song_presentation_title_signals_it(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-11-23',
            'service' => SermonService::Morning->value,
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
            'section_type' => ServiceSectionType::Other->value,
            'section_order' => 2,
            'title' => null,
            'confidence' => 0.5,
            'metadata' => [
                'confidence_level' => 'low',
                'classification_mode' => 'audio_only',
            ],
        ]);

        $result = app(OosAlignmentService::class)->alignForProcessingLog($processingLog, $churchService);

        $section->refresh();

        // Strong title match → CHILDRENS_TALK, but requires manual review (inferred, not explicit)
        $this->assertSame(ServiceSectionType::ChildrensTalk->value, $section->section_type->value);
        $this->assertSame(ServiceSectionType::Other->value, $section->metadata['oos_alignment']['reclassified_from'] ?? null);
        $this->assertSame('oos_alignment', $section->metadata['oos_alignment']['reclassified_by'] ?? null);
        $this->assertNotContains('ambiguous_childrens_talk', $section->metadata['review_flags'] ?? []);
        $this->assertContains('inferred_childrens_talk', $section->metadata['review_flags'] ?? []);
        $this->assertTrue($section->needs_manual_review);
        $this->assertContains('manual_review_sections', $result['review_triggers']);
    }

    #[Test]
    public function it_classifies_a_presentation_titled_notices_as_notices_via_strong_title_match(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-11-24',
            'service' => SermonService::Morning->value,
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
            'section_type' => ServiceSectionType::Other->value,
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

        // Strong notices title → NOTICES, no review required
        $this->assertSame(ServiceSectionType::Notices->value, $section->section_type->value);
        $this->assertNotSame(ServiceSectionType::ChildrensTalk->value, $section->section_type->value);
        $this->assertFalse($section->needs_manual_review);
        $this->assertNotContains('manual_review_sections', $result['review_triggers']);
    }

    #[Test]
    public function it_does_not_reclassify_a_generic_post_song_presentation_to_childrens_talk(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-11-26',
            'service' => SermonService::Morning->value,
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'title' => 'Amazing Grace',
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 2,
            'type' => 'presentations',
            'title' => 'Interview Slides',
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $churchService->id,
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::Other->value,
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

        // Weak position-only evidence: section stays OTHER, suspected_type hint written to metadata
        $this->assertSame(ServiceSectionType::Other, $section->section_type);
        $this->assertFalse($section->needs_manual_review);
        $this->assertSame('other', $section->metadata['oos_alignment']['presentation_inference']['resolved_type'] ?? null);
        $this->assertSame('childrens_talk', $section->metadata['oos_alignment']['presentation_inference']['suspected_type'] ?? null);
        $this->assertSame('weak', $section->metadata['oos_alignment']['presentation_inference']['evidence'] ?? null);
    }

    #[Test]
    public function it_marks_title_inferred_childrens_talks_for_manual_review(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-11-27',
            'service' => SermonService::Morning->value,
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
            'section_type' => ServiceSectionType::Other->value,
            'section_order' => 2,
            'title' => null,
            'confidence' => 0.5,
            'metadata' => [
                'confidence_level' => 'low',
                'classification_mode' => 'audio_only',
            ],
        ]);

        $result = app(OosAlignmentService::class)->alignForProcessingLog($processingLog, $churchService);

        $section->refresh();

        $this->assertSame(ServiceSectionType::ChildrensTalk, $section->section_type);
        $this->assertTrue($section->needs_manual_review);
        $this->assertContains('inferred_childrens_talk', $section->metadata['review_flags'] ?? []);
        $this->assertContains('manual_review_sections', $result['review_triggers']);
    }

    #[Test]
    public function it_allows_explicit_presentation_section_type_to_bypass_manual_review(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-11-28',
            'service' => SermonService::Morning->value,
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
            'section_type' => ServiceSectionType::ChildrensTalk,
            'title' => 'Slides',
            'metadata' => null,
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $churchService->id,
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::Other->value,
            'section_order' => 2,
            'title' => null,
            'confidence' => 0.5,
            'metadata' => [
                'confidence_level' => 'low',
                'classification_mode' => 'audio_only',
            ],
        ]);

        $result = app(OosAlignmentService::class)->alignForProcessingLog($processingLog, $churchService);

        $section->refresh();

        // Explicit section_type column → trusted, no review flag
        $this->assertSame(ServiceSectionType::ChildrensTalk, $section->section_type);
        $this->assertFalse($section->needs_manual_review);
        $this->assertNotContains('inferred_childrens_talk', $section->metadata['review_flags'] ?? []);
        $this->assertNotContains('manual_review_sections', $result['review_triggers']);
    }

    #[Test]
    public function it_records_suspected_childrens_talk_metadata_without_reclassifying_when_evidence_is_weak(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-11-29',
            'service' => SermonService::Morning->value,
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'title' => 'Come Thou Fount',
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 2,
            'type' => 'presentations',
            'title' => 'Prayer Prompts',
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $churchService->id,
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::Other->value,
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

        $this->assertSame(ServiceSectionType::Other, $section->section_type);
        $this->assertFalse($section->needs_manual_review);
        $inference = $section->metadata['oos_alignment']['presentation_inference'] ?? null;
        $this->assertIsArray($inference);
        $this->assertSame('other', $inference['resolved_type']);
        $this->assertSame('childrens_talk', $inference['suspected_type']);
        $this->assertSame('weak', $inference['evidence']);
        $this->assertSame('post_first_song_presentation', $inference['reason']);
    }

    #[Test]
    public function it_clears_ambiguous_childrens_talk_when_the_oos_is_no_longer_ambiguous(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-11-30',
            'service' => SermonService::Morning->value,
        ]);

        $song = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'title' => 'To God Be The Glory',
        ]);

        $talkItem1 = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 2,
            'type' => 'presentations',
            'title' => "Children's Talk Part 1",
        ]);

        $talkItem2 = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 3,
            'type' => 'presentations',
            'title' => "Children's Talk Part 2",
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $churchService->id,
        ]);

        $songSection = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::Song->value,
            'section_order' => 1,
            'title' => 'To God Be The Glory',
            'confidence' => 0.85,
            'metadata' => ['confidence_level' => 'high', 'classification_mode' => 'audio_only'],
        ]);

        $talkSection1 = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::Other->value,
            'section_order' => 2,
            'title' => null,
            'confidence' => 0.5,
            'metadata' => ['confidence_level' => 'low', 'classification_mode' => 'audio_only'],
        ]);

        $talkSection2 = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::Other->value,
            'section_order' => 3,
            'title' => null,
            'confidence' => 0.5,
            'metadata' => ['confidence_level' => 'low', 'classification_mode' => 'audio_only'],
        ]);

        // First alignment: two childrens-talk items → both get ambiguous flag
        app(OosAlignmentService::class)->alignForProcessingLog($processingLog, $churchService);

        $talkSection1->refresh();
        $talkSection2->refresh();

        $this->assertContains('ambiguous_childrens_talk', $talkSection1->metadata['review_flags'] ?? []);
        $this->assertContains('ambiguous_childrens_talk', $talkSection2->metadata['review_flags'] ?? []);

        // Remove one of the two childrens-talk items from the OoS
        $talkItem2->delete();
        $talkSection2->delete();

        // Second alignment: only one childrens-talk item remains → no ambiguity
        app(OosAlignmentService::class)->alignForProcessingLog($processingLog->fresh(), $churchService->fresh());

        $talkSection1->refresh();

        $this->assertNotContains('ambiguous_childrens_talk', $talkSection1->metadata['review_flags'] ?? []);
        // Still needs review because it was inferred from a title, not explicit
        $this->assertContains('inferred_childrens_talk', $talkSection1->metadata['review_flags'] ?? []);
    }

    #[Test]
    public function it_clears_inferred_childrens_talk_flags_when_a_section_no_longer_matches_that_rule(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-12-01',
            'service' => SermonService::Morning->value,
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'title' => 'How Great Thou Art',
        ]);

        $talkItem = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 2,
            'type' => 'presentations',
            'title' => "Children's Talk",
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $churchService->id,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::Song->value,
            'section_order' => 1,
            'title' => 'How Great Thou Art',
            'confidence' => 0.85,
            'metadata' => ['confidence_level' => 'high', 'classification_mode' => 'audio_only'],
        ]);

        $talkSection = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::Other->value,
            'section_order' => 2,
            'title' => null,
            'confidence' => 0.5,
            'metadata' => ['confidence_level' => 'low', 'classification_mode' => 'audio_only'],
        ]);

        // First alignment: strong title match → inferred_childrens_talk
        app(OosAlignmentService::class)->alignForProcessingLog($processingLog, $churchService);

        $talkSection->refresh();
        $this->assertContains('inferred_childrens_talk', $talkSection->metadata['review_flags'] ?? []);
        $this->assertTrue($talkSection->needs_manual_review);

        // Update the OoS item to use explicit promoted state instead — now no review needed
        $talkItem->forceFill([
            'section_type' => ServiceSectionType::ChildrensTalk,
            'metadata' => null,
        ])->saveQuietly();

        // Second alignment: explicit column → no review flag
        app(OosAlignmentService::class)->alignForProcessingLog($processingLog->fresh(), $churchService->fresh());

        $talkSection->refresh();

        $this->assertNotContains('inferred_childrens_talk', $talkSection->metadata['review_flags'] ?? []);
        $this->assertFalse($talkSection->needs_manual_review);
    }

    #[Test]
    public function it_marks_the_parent_service_for_review_when_childrens_talk_inference_requires_manual_review(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-12-02',
            'service' => SermonService::Morning->value,
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'title' => 'Amazing Grace',
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

        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::Song->value,
            'section_order' => 1,
            'title' => 'Amazing Grace',
            'confidence' => 0.85,
            'metadata' => ['confidence_level' => 'high', 'classification_mode' => 'audio_only'],
        ]);

        $talkSection = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::Other->value,
            'section_order' => 2,
            'title' => null,
            'confidence' => 0.5,
            'metadata' => ['confidence_level' => 'low', 'classification_mode' => 'audio_only'],
        ]);

        $result = app(OosAlignmentService::class)->alignForProcessingLog($processingLog, $churchService);

        $talkSection->refresh();
        $churchService->refresh();

        $this->assertTrue($talkSection->needs_manual_review);
        $this->assertContains('manual_review_sections', $result['review_triggers']);
        $this->assertTrue($churchService->needs_review);
        $this->assertContains('manual_review_sections', $churchService->import_metadata['review_triggers'] ?? []);
    }

    #[Test]
    public function it_flags_for_review_when_multiple_post_song_presentations_exist(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-11-25',
            'service' => SermonService::Morning->value,
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
            'section_type' => ServiceSectionType::Other->value,
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
            'section_type' => ServiceSectionType::Other->value,
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

        $this->assertSame(ServiceSectionType::ChildrensTalk->value, $firstSection->section_type->value);
        $this->assertSame(ServiceSectionType::ChildrensTalk->value, $secondSection->section_type->value);
        $this->assertTrue($firstSection->needs_manual_review);
        $this->assertTrue($secondSection->needs_manual_review);
        $this->assertContains('ambiguous_childrens_talk', $firstSection->metadata['review_flags'] ?? []);
        $this->assertContains('ambiguous_childrens_talk', $secondSection->metadata['review_flags'] ?? []);
        $this->assertSame('ambiguous_childrens_talk', $firstSection->metadata['review_reason'] ?? null);
        $this->assertSame('ambiguous_childrens_talk', $secondSection->metadata['review_reason'] ?? null);
    }

    // ---- Dismissal-marker children's talk inference ----

    #[Test]
    public function it_flags_long_preceding_bible_reading_as_inferred_childrens_talk_when_dismissal_phrase_found(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-12-07',
            'service' => SermonService::Morning->value,
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'presentations',
            'title' => 'Bible Reading',
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 2,
            'type' => 'presentations',
            'title' => 'Notices',
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $churchService->id,
        ]);

        // Long bible_reading section that actually contains a children's talk
        $longBibleReading = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::BibleReading->value,
            'section_order' => 1,
            'start_time' => 606.0,
            'end_time' => 1469.0,
            'confidence' => 0.6,
            'metadata' => [
                'confidence_level' => 'low',
                'classification_mode' => 'ai_transcript',
                'transcript' => 'Good morning children. Can anyone tell me what this picture shows? Let us look at what God has done.',
            ],
        ]);

        // Following section whose transcript contains a dismissal marker
        $followingSection = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::Other->value,
            'section_order' => 2,
            'start_time' => 1469.0,
            'end_time' => 1550.0,
            'confidence' => 0.5,
            'metadata' => [
                'confidence_level' => 'low',
                'classification_mode' => 'ai_transcript',
                'transcript' => 'The young people go out to their classes now. Let us open our hymnals.',
            ],
        ]);

        app(OosAlignmentService::class)->alignForProcessingLog($processingLog, $churchService);

        $longBibleReading->refresh();

        $this->assertTrue($longBibleReading->needs_manual_review);
        $this->assertContains('inferred_childrens_talk', $longBibleReading->metadata['review_flags'] ?? []);
        $this->assertSame('inferred_childrens_talk', $longBibleReading->metadata['review_reason']);
    }

    #[Test]
    public function it_does_not_flag_short_preceding_section_for_inferred_childrens_talk(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-12-08',
            'service' => SermonService::Morning->value,
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'presentations',
            'title' => 'Welcome',
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 2,
            'type' => 'presentations',
            'title' => 'Notices',
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $churchService->id,
        ]);

        // Short section (< 5 min) — should not be flagged
        $shortSection = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::BibleReading->value,
            'section_order' => 1,
            'start_time' => 100.0,
            'end_time' => 220.0, // 2 minutes
            'confidence' => 0.6,
            'metadata' => [
                'confidence_level' => 'low',
                'classification_mode' => 'ai_transcript',
                'transcript' => 'A short reading from the Bible.',
            ],
        ]);

        $followingSection = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::Other->value,
            'section_order' => 2,
            'start_time' => 220.0,
            'end_time' => 300.0,
            'confidence' => 0.5,
            'metadata' => [
                'confidence_level' => 'low',
                'classification_mode' => 'ai_transcript',
                'transcript' => 'The young people go out to their classes now.',
            ],
        ]);

        app(OosAlignmentService::class)->alignForProcessingLog($processingLog, $churchService);

        $shortSection->refresh();

        $this->assertNotContains('inferred_childrens_talk', $shortSection->metadata['review_flags'] ?? []);
    }

    #[Test]
    public function it_clears_dismissal_inferred_childrens_talk_flag_on_rerun_when_no_longer_applicable(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-12-09',
            'service' => SermonService::Morning->value,
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'presentations',
            'title' => 'Bible Reading',
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 2,
            'type' => 'presentations',
            'title' => 'Notices',
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $churchService->id,
        ]);

        $longSection = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::BibleReading->value,
            'section_order' => 1,
            'start_time' => 600.0,
            'end_time' => 1500.0,
            'confidence' => 0.6,
            'metadata' => [
                'confidence_level' => 'low',
                'classification_mode' => 'ai_transcript',
                'transcript' => 'Let us read from the Bible today.',
            ],
        ]);

        $dismissalSection = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::Other->value,
            'section_order' => 2,
            'start_time' => 1500.0,
            'end_time' => 1560.0,
            'confidence' => 0.5,
            'metadata' => [
                'confidence_level' => 'low',
                'classification_mode' => 'ai_transcript',
                'transcript' => 'The young people go out to their classes now.',
            ],
        ]);

        // First alignment — flag should be set
        app(OosAlignmentService::class)->alignForProcessingLog($processingLog, $churchService);

        $longSection->refresh();
        $this->assertContains('inferred_childrens_talk', $longSection->metadata['review_flags'] ?? []);

        // Update the dismissal section so it no longer contains the marker
        $updatedMetadata = $dismissalSection->metadata->toArray();
        $updatedMetadata['transcript'] = 'We continue our service with a time of prayer.';
        $dismissalSection->metadata = ServiceSectionMetadata::fromArray($updatedMetadata);
        $dismissalSection->save();

        // Second alignment — flag should be cleared (baseline restorer removes OOS flags, and dismissal no longer triggers)
        app(OosAlignmentService::class)->alignForProcessingLog($processingLog->fresh(), $churchService->fresh());

        $longSection->refresh();
        $this->assertNotContains('inferred_childrens_talk', $longSection->metadata['review_flags'] ?? []);
    }
}
