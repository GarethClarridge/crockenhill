<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Enums\SermonService;
use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Services\OosAlignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OosAlignmentServiceCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_persists_the_resolved_church_service_link_when_items_are_missing(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-08-03',
            'service' => SermonService::Morning->value,
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => null,
            'extracted_date' => $churchService->date,
            'extracted_service' => $churchService->service,
        ]);

        ServiceSection::factory()->create([
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

        $result = app(OosAlignmentService::class)->alignForProcessingLog($processingLog);

        $processingLog->refresh();

        $this->assertFalse($result['aligned']);
        $this->assertSame($churchService->id, $processingLog->church_service_id);
    }

    #[Test]
    public function it_persists_the_resolved_church_service_link_when_sections_are_missing(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-08-10',
            'service' => SermonService::Morning->value,
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'custom',
            'title' => 'Opening Prayer',
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => null,
            'extracted_date' => $churchService->date,
            'extracted_service' => $churchService->service,
        ]);

        $result = app(OosAlignmentService::class)->alignForProcessingLog($processingLog);

        $processingLog->refresh();

        $this->assertFalse($result['aligned']);
        $this->assertSame($churchService->id, $processingLog->church_service_id);
    }

    #[Test]
    public function it_restores_baseline_confidence_between_reruns_and_preserves_non_oos_review_flags(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-08-17',
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
            'section_type' => ServiceSectionType::PRAYER->value,
            'section_order' => 1,
            'title' => null,
            'confidence' => 0.5,
            'needs_manual_review' => true,
            'metadata' => [
                'confidence_level' => 'low',
                'classification_mode' => 'ai_transcript',
                'review_flags' => [
                    'external_review_flag',
                    'oos_structure_mismatch',
                    'inferred_childrens_talk',
                ],
                'review_reason' => 'external_review_flag',
            ],
        ]);

        app(OosAlignmentService::class)->alignForProcessingLog($processingLog, $churchService);

        $section->refresh();
        $firstPassConfidence = $section->confidence;

        $this->assertEqualsWithDelta(0.85, $firstPassConfidence, 0.001);
        $this->assertContains('external_review_flag', $section->metadata['review_flags'] ?? []);
        $this->assertNotContains('oos_structure_mismatch', $section->metadata['review_flags'] ?? []);
        $this->assertNotContains('inferred_childrens_talk', $section->metadata['review_flags'] ?? []);
        $this->assertTrue($section->needs_manual_review);

        app(OosAlignmentService::class)->alignForProcessingLog($processingLog->fresh(), $churchService->fresh());

        $section->refresh();

        $this->assertEqualsWithDelta($firstPassConfidence, $section->confidence, 0.001);
        $this->assertContains('external_review_flag', $section->metadata['review_flags'] ?? []);
        $this->assertNotContains('oos_structure_mismatch', $section->metadata['review_flags'] ?? []);
        $this->assertNotContains('inferred_childrens_talk', $section->metadata['review_flags'] ?? []);
        $this->assertTrue($section->needs_manual_review);
    }

    #[Test]
    public function it_uses_linked_song_canonical_key_as_a_positive_song_match_candidate(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-08-24',
            'service' => SermonService::Morning->value,
        ]);

        $songItem = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'title' => 'Be Thou My Vision',
            'openlp_search_title' => 'be thou my vision',
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
                'linked_song_canonical_key' => Song::canonicalizeKey('Be Thou My Vision'),
            ],
        ]);

        $result = app(OosAlignmentService::class)->alignForProcessingLog($processingLog, $churchService);

        $section->refresh();

        $this->assertTrue($result['aligned']);
        $this->assertSame($songItem->id, $section->church_service_item_id);
        $this->assertSame('Be Thou My Vision', $section->title);
        $this->assertSame(ServiceSectionSongMatchType::CONFIRMED, $section->song_match_type);
        $this->assertArrayNotHasKey('song_match_type', $section->metadata['oos_alignment'] ?? []);
    }

    #[Test]
    public function it_does_not_emit_a_late_arrival_change_trigger_when_alignment_is_unchanged(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-08-31',
            'service' => SermonService::Morning->value,
        ]);

        $item = ChurchServiceItem::factory()->create([
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
            'church_service_item_id' => $item->id,
            'section_type' => ServiceSectionType::PRAYER->value,
            'section_order' => 1,
            'title' => 'Opening Prayer',
            'confidence' => 0.85,
            'metadata' => [
                'confidence_level' => 'high',
                'classification_mode' => 'ai_transcript',
                'confidence_score' => 0.85,
                'oos_alignment' => [
                    'base_confidence' => 0.5,
                    'base_needs_manual_review' => false,
                    'base_title' => null,
                    'base_church_service_item_id' => null,
                    'matched_item_id' => $item->id,
                    'matched_item_type' => $item->type,
                    'matched_item_title' => $item->title,
                ],
            ],
        ]);

        $result = app(OosAlignmentService::class)->alignForProcessingLog(
            $processingLog,
            $churchService,
            lateArrival: true
        );

        $this->assertSame([], $result['review_triggers']);
    }

    #[Test]
    public function it_marks_the_current_section_as_unexpected_when_the_expected_type_appears_later_in_remaining_sections(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-09-07',
            'service' => SermonService::Morning->value,
        ]);

        $prayerItem = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'custom',
            'title' => 'Opening Prayer',
        ]);

        $readingItem = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 2,
            'type' => 'bibles',
            'title' => 'Psalm 23',
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $churchService->id,
        ]);

        $unexpectedSection = ServiceSection::factory()->create([
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

        $prayerSection = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::PRAYER->value,
            'section_order' => 2,
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
            'section_order' => 3,
            'title' => null,
            'confidence' => 0.5,
            'metadata' => [
                'confidence_level' => 'low',
                'classification_mode' => 'ai_transcript',
            ],
        ]);

        $result = app(OosAlignmentService::class)->alignForProcessingLog($processingLog, $churchService);

        $unexpectedSection->refresh();
        $prayerSection->refresh();
        $readingSection->refresh();

        $this->assertContains('oos_structure_mismatch', $result['review_triggers']);
        $this->assertSame('unexpected_detected_section', $unexpectedSection->metadata['oos_alignment']['mismatch_reason'] ?? null);
        $this->assertSame($prayerItem->id, $prayerSection->church_service_item_id);
        $this->assertSame($readingItem->id, $readingSection->church_service_item_id);
    }

    #[Test]
    public function it_skips_a_missing_item_when_the_current_section_type_appears_later_in_remaining_items(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-09-14',
            'service' => SermonService::Morning->value,
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'custom',
            'title' => 'Opening Prayer',
        ]);

        $readingItem = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 2,
            'type' => 'bibles',
            'title' => 'Psalm 121',
        ]);

        $noticesItem = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 3,
            'type' => 'custom',
            'title' => 'Notices',
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $churchService->id,
        ]);

        $noticesSection = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::BIBLE_READING->value,
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
            'section_type' => ServiceSectionType::NOTICES->value,
            'section_order' => 2,
            'title' => 'Notices',
            'confidence' => 0.5,
            'metadata' => [
                'confidence_level' => 'low',
                'classification_mode' => 'audio_only',
            ],
        ]);

        $result = app(OosAlignmentService::class)->alignForProcessingLog($processingLog, $churchService);

        $noticesSection->refresh();
        $readingSection->refresh();

        $this->assertContains('oos_structure_mismatch', $result['review_triggers']);
        $this->assertSame($readingItem->id, $noticesSection->church_service_item_id);
        $this->assertArrayNotHasKey('mismatch_reason', $noticesSection->metadata['oos_alignment'] ?? []);
        $this->assertFalse($noticesSection->needs_manual_review);
        $this->assertSame($noticesItem->id, $readingSection->church_service_item_id);
    }

    #[Test]
    public function it_records_a_terminal_type_mismatch_when_neither_structural_lookahead_applies(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-09-21',
            'service' => SermonService::Morning->value,
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'custom',
            'title' => 'Opening Prayer',
        ]);

        $readingItem = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 2,
            'type' => 'bibles',
            'title' => 'Romans 8',
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $churchService->id,
        ]);

        $mismatchSection = ServiceSection::factory()->create([
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

        $mismatchSection->refresh();
        $readingSection->refresh();

        $this->assertContains('oos_structure_mismatch', $result['review_triggers']);
        $this->assertSame('oos_type_mismatch', $mismatchSection->metadata['oos_alignment']['mismatch_reason'] ?? null);
        $this->assertSame($readingItem->id, $readingSection->church_service_item_id);
    }
}
