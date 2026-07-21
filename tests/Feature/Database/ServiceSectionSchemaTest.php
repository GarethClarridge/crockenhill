<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionStatus;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\ServiceSection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServiceSectionSchemaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The phase-two columns on `service_sections` and `media_processing_logs` are
     * exercised behaviourally by the constraint/cascade tests below and by the
     * livestream Integration job suite. Indexes have no behavioural witness, so this
     * guardrail retains only the index assertions.
     */
    #[Test]
    public function it_creates_phase_two_service_section_indexes(): void
    {
        $this->assertTrue(Schema::hasColumn('service_sections', 'summary'));
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
            'section_type' => ServiceSectionType::Song->value,
            'status' => ServiceSectionStatus::Identified->value,
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

    #[Test]
    public function confidence_values_outside_zero_to_one_are_rejected_by_the_database(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->create();

        $this->expectException(QueryException::class);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'confidence' => 1.5,
        ]);
    }

    #[Test]
    public function invalid_status_values_are_rejected_by_the_database(): void
    {
        $section = ServiceSection::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('service_sections')
            ->where('id', $section->id)
            ->update(['status' => 'free_text_drift']);
    }

    #[Test]
    public function invalid_publication_status_values_are_rejected_by_the_database(): void
    {
        $section = ServiceSection::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('service_sections')
            ->where('id', $section->id)
            ->update(['publication_status' => 'some_future_state']);
    }

    #[Test]
    public function timing_invariants_are_rejected_by_the_database(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->create();

        $this->expectException(QueryException::class);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'start_time' => 120.0,
            'end_time' => 110.0,
            'duration' => 10.0,
        ]);
    }

    #[Test]
    public function published_sections_must_have_a_published_sermon_link_and_timestamp(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->create();
        $sermon = Sermon::factory()->create();
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'publication_status' => ServiceSectionPublicationStatus::Published->value,
            'published_sermon_id' => $sermon->id,
            'published_at' => now(),
            'extracted_video_path' => 'sermons/sections/10/video.mp4',
            'extracted_audio_path' => 'sermons/audio/section-10.mp3',
            'extracted_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('service_sections')
            ->where('id', $section->id)
            ->update([
                'published_sermon_id' => null,
                'published_at' => null,
            ]);
    }

    #[Test]
    public function unpublished_sections_cannot_keep_a_published_sermon_link(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->create();
        $sermon = Sermon::factory()->create();
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'publication_status' => ServiceSectionPublicationStatus::Approved->value,
            'published_sermon_id' => null,
            'published_at' => null,
            'extracted_video_path' => 'sermons/sections/11/video.mp4',
            'extracted_audio_path' => 'sermons/audio/section-11.mp3',
            'extracted_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('service_sections')
            ->where('id', $section->id)
            ->update([
                'published_sermon_id' => $sermon->id,
                'published_at' => now(),
            ]);
    }

    #[Test]
    public function approved_sections_must_have_extracted_media_and_timestamp(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->create();
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'publication_status' => ServiceSectionPublicationStatus::Approved->value,
            'extracted_video_path' => 'sermons/sections/12/video.mp4',
            'extracted_audio_path' => 'sermons/audio/section-12.mp3',
            'extracted_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('service_sections')
            ->where('id', $section->id)
            ->update([
                'extracted_video_path' => null,
                'extracted_audio_path' => null,
                'extracted_at' => null,
            ]);
    }

    #[Test]
    public function removed_skipped_status_is_rejected_by_the_database(): void
    {
        $section = ServiceSection::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('service_sections')
            ->where('id', $section->id)
            ->update(['status' => 'skipped']);
    }

    #[Test]
    public function approved_sections_must_have_extracted_audio_path_to_satisfy_fortified_constraint(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->create();
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::Sermon->value,
            'publication_status' => ServiceSectionPublicationStatus::Approved->value,
            'extracted_video_path' => 'sermons/sections/13/video.mp4',
            'extracted_audio_path' => 'sermons/audio/section-13.mp3',
            'extracted_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('service_sections')
            ->where('id', $section->id)
            ->update([
                'extracted_audio_path' => null,
            ]);
    }

    #[Test]
    public function invalid_section_type_values_are_rejected_by_the_database(): void
    {
        $section = ServiceSection::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('service_sections')
            ->where('id', $section->id)
            ->update(['section_type' => 'invalid_type_blob']);
    }
}
