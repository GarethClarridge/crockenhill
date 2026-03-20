<?php

declare(strict_types=1);

namespace Tests\Feature;

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

class StructuralSectionAlignerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * When a section has no matching type but the expected type appears later in the
     * remaining sections, the current section is marked as "unexpected_detected_section"
     * and the walk advances the section index only.
     */
    #[Test]
    public function it_marks_section_as_unexpected_when_expected_type_appears_later_in_sections(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-11-09',
            'service' => SermonService::MORNING->value,
        ]);

        $prayerItem = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'custom',
            'title' => 'Opening Prayer',
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 2,
            'type' => 'custom',
            'title' => 'Welcome',
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $churchService->id,
        ]);

        // Detected section order: NOTICES (unexpected), PRAYER (correct for first item)
        $noticesSection = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::NOTICES->value,
            'section_order' => 1,
            'title' => 'Notices',
            'confidence' => 0.5,
            'metadata' => ['classification_mode' => 'ai_transcript'],
        ]);

        $prayerSection = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::PRAYER->value,
            'section_order' => 2,
            'title' => null,
            'confidence' => 0.5,
            'metadata' => ['classification_mode' => 'ai_transcript'],
        ]);

        $result = app(OosAlignmentService::class)->alignForProcessingLog($processingLog, $churchService);

        $noticesSection->refresh();
        $prayerSection->refresh();

        $this->assertContains('oos_structure_mismatch', $result['review_triggers']);
        $this->assertSame('unexpected_detected_section', $noticesSection->metadata['oos_alignment']['mismatch_reason'] ?? null);
        $this->assertTrue($noticesSection->needs_manual_review);

        // Prayer section was correctly matched after skipping the unexpected notices section
        $this->assertSame($prayerItem->id, $prayerSection->church_service_item_id);
        $this->assertFalse($prayerSection->needs_manual_review);
    }

    /**
     * When the current item type doesn't match and the same type appears later in
     * the remaining items, the walk advances the item index without marking a mismatch
     * on the section.
     */
    #[Test]
    public function it_skips_an_item_when_the_section_type_appears_later_in_remaining_items(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-11-16',
            'service' => SermonService::MORNING->value,
        ]);

        // OoS has: SERMON item first, then PRAYER item
        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'custom',
            'title' => 'The Message',
        ]);

        $prayerItem = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 2,
            'type' => 'custom',
            'title' => 'Closing Prayer',
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $churchService->id,
        ]);

        // Detected: PRAYER first — PRAYER item is further along in remaining items, so item is skipped
        $prayerSection = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::PRAYER->value,
            'section_order' => 1,
            'title' => 'Closing Prayer',
            'confidence' => 0.5,
            'metadata' => ['classification_mode' => 'ai_transcript'],
        ]);

        $result = app(OosAlignmentService::class)->alignForProcessingLog($processingLog, $churchService);

        $prayerSection->refresh();

        $this->assertContains('oos_structure_mismatch', $result['review_triggers']);
        // The prayer section should be matched to the prayer item (sermon item was skipped)
        $this->assertSame($prayerItem->id, $prayerSection->church_service_item_id);
        $this->assertFalse($prayerSection->needs_manual_review);
        $this->assertArrayNotHasKey('mismatch_reason', $prayerSection->metadata['oos_alignment'] ?? []);
    }

    /**
     * When neither lookahead branch applies, both section and item advance and the
     * section is marked with 'oos_type_mismatch'.
     */
    #[Test]
    public function it_records_oos_type_mismatch_when_neither_lookahead_branch_applies(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-11-23',
            'service' => SermonService::MORNING->value,
        ]);

        $bibleItem = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'bibles',
            'title' => 'John 3:16',
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $churchService->id,
        ]);

        // Section is NOTICES, item is BIBLE_READING — neither type appears in remaining
        $mismatchSection = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::NOTICES->value,
            'section_order' => 1,
            'title' => 'Notices',
            'confidence' => 0.5,
            'metadata' => ['classification_mode' => 'ai_transcript'],
        ]);

        $result = app(OosAlignmentService::class)->alignForProcessingLog($processingLog, $churchService);

        $mismatchSection->refresh();

        $this->assertContains('oos_structure_mismatch', $result['review_triggers']);
        $this->assertSame('oos_type_mismatch', $mismatchSection->metadata['oos_alignment']['mismatch_reason'] ?? null);
        $this->assertSame($bibleItem->id, $mismatchSection->metadata['oos_alignment']['expected_item_id'] ?? null);
        $this->assertTrue($mismatchSection->needs_manual_review);
    }

    /**
     * An OTHER section can be reclassified by OoS into an authoritative structural type
     * (e.g. BIBLE_READING, PRAYER). The reclassification_from and reclassified_by keys
     * must be written to metadata.
     */
    #[Test]
    public function it_reclassifies_other_section_into_authoritative_structural_type(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-11-30',
            'service' => SermonService::MORNING->value,
        ]);

        $readingItem = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'bibles',
            'title' => 'Romans 8:28',
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $churchService->id,
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::OTHER->value,
            'section_order' => 1,
            'title' => null,
            'confidence' => 0.5,
            'metadata' => ['classification_mode' => 'audio_only'],
        ]);

        app(OosAlignmentService::class)->alignForProcessingLog($processingLog, $churchService);

        $section->refresh();

        $this->assertSame(ServiceSectionType::BIBLE_READING, $section->section_type);
        $this->assertSame($readingItem->id, $section->church_service_item_id);
        $this->assertSame(ServiceSectionType::OTHER->value, $section->metadata['oos_alignment']['reclassified_from'] ?? null);
        $this->assertSame('oos_alignment', $section->metadata['oos_alignment']['reclassified_by'] ?? null);
        $this->assertFalse($section->needs_manual_review);
    }

    /**
     * Bible reading sections receive a reading_reference metadata entry taken from
     * the item title when aligned.
     */
    #[Test]
    public function it_writes_reading_reference_on_bible_reading_match(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-12-07',
            'service' => SermonService::MORNING->value,
        ]);

        $readingItem = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'bibles',
            'title' => 'Psalm 23',
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $churchService->id,
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::BIBLE_READING->value,
            'section_order' => 1,
            'title' => null,
            'confidence' => 0.5,
            'metadata' => ['classification_mode' => 'audio_only'],
        ]);

        app(OosAlignmentService::class)->alignForProcessingLog($processingLog, $churchService);

        $section->refresh();

        $this->assertSame($readingItem->id, $section->church_service_item_id);
        $this->assertSame('Psalm 23', $section->metadata['reading_reference'] ?? null);
    }

    /**
     * Late-arrival trigger: when alignForProcessingLog is called with lateArrival=true
     * and the alignment outcome changes from the captured before-state, the
     * 'late_oos_alignment_changed' trigger must be emitted.
     */
    #[Test]
    public function it_emits_late_arrival_trigger_when_alignment_changes(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-12-14',
            'service' => SermonService::MORNING->value,
        ]);

        $prayerItem = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'custom',
            'title' => 'Opening Prayer',
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $churchService->id,
        ]);

        // Section has no existing match — alignment will change it
        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::PRAYER->value,
            'section_order' => 1,
            'title' => null,
            'confidence' => 0.5,
            'metadata' => ['classification_mode' => 'ai_transcript'],
        ]);

        $result = app(OosAlignmentService::class)->alignForProcessingLog(
            $processingLog,
            $churchService,
            lateArrival: true
        );

        $this->assertContains('late_oos_alignment_changed', $result['review_triggers']);
    }

    /**
     * Late-arrival trigger: when alignment produces no change from before-state,
     * 'late_oos_alignment_changed' must NOT be emitted.
     */
    #[Test]
    public function it_does_not_emit_late_arrival_trigger_when_alignment_is_unchanged(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-12-21',
            'service' => SermonService::MORNING->value,
        ]);

        $item = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'custom',
            'title' => 'Closing Prayer',
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $churchService->id,
        ]);

        // Section already matched from a prior alignment run — second run should not change outcome
        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => $item->id,
            'section_type' => ServiceSectionType::PRAYER->value,
            'section_order' => 1,
            'title' => 'Closing Prayer',
            'confidence' => 0.85,
            'metadata' => [
                'confidence_level' => 'high',
                'confidence_score' => 0.85,
                'classification_mode' => 'ai_transcript',
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

        $this->assertNotContains('late_oos_alignment_changed', $result['review_triggers']);
    }
}
