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
    public function it_flags_unmatched_song_sections_for_review(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-06-21',
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
        $this->assertSame('unmatched_song_section', $section->metadata['review_reason']);
        $this->assertTrue($churchService->needs_review);
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
}
