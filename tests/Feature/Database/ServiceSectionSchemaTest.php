<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Enums\ServiceSectionStatus;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServiceSectionSchemaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_creates_phase_two_service_section_columns_and_indexes(): void
    {
        $this->assertTrue(Schema::hasColumns('service_sections', [
            'id',
            'media_processing_log_id',
            'church_service_item_id',
            'section_type',
            'section_order',
            'title',
            'start_time',
            'end_time',
            'duration',
            'status',
            'needs_manual_review',
            'source_segment_ids',
            'metadata',
            'publication_status',
            'published_sermon_id',
            'published_at',
            'extracted_video_path',
            'extracted_audio_path',
            'extracted_at',
            'unpublished_expires_at',
            'created_at',
            'updated_at',
        ]));

        $this->assertTrue(Schema::hasColumns('media_processing_logs', [
            'extracted_date',
            'extracted_service',
            'church_service_id',
        ]));

        $this->assertTrue(Schema::hasIndex('service_sections', 'service_sections_log_order_unique'));
        $this->assertTrue(Schema::hasIndex('service_sections', 'service_sections_log_type_index'));
        $this->assertTrue(Schema::hasIndex('service_sections', 'service_sections_needs_review_index'));
        $this->assertTrue(Schema::hasIndex('service_sections', 'service_sections_church_service_item_index'));
        $this->assertTrue(Schema::hasIndex('service_sections', 'service_sections_publication_status_index'));
        $this->assertTrue(Schema::hasIndex('service_sections', 'service_sections_unpublished_expires_at_index'));
        $this->assertTrue(Schema::hasIndex('service_sections', 'service_sections_published_sermon_id_unique'));
        $this->assertTrue(Schema::hasIndex('media_processing_logs', 'media_processing_logs_extracted_identity_index'));
    }

    #[Test]
    public function deleting_processing_log_cascades_service_sections(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->create();
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
        ]);

        $processingLog->delete();

        $this->assertDatabaseMissing('service_sections', ['id' => $section->id]);
    }

    #[Test]
    public function force_deleting_church_service_item_sets_service_section_reference_to_null(): void
    {
        $churchServiceItem = ChurchServiceItem::factory()->create();
        $section = ServiceSection::factory()->create([
            'church_service_item_id' => $churchServiceItem->id,
            'section_type' => ServiceSectionType::SONG->value,
            'status' => ServiceSectionStatus::IDENTIFIED->value,
        ]);

        $churchServiceItem->forceDelete();
        $section->refresh();

        $this->assertNull($section->church_service_item_id);
    }

    #[Test]
    public function deleting_church_service_sets_media_processing_log_reference_to_null(): void
    {
        $churchService = ChurchService::factory()->create();
        $processingLog = MediaProcessingLog::factory()->create([
            'church_service_id' => $churchService->id,
        ]);

        $churchService->delete();
        $processingLog->refresh();

        $this->assertNull($processingLog->church_service_id);
    }
}
