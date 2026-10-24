<?php

declare(strict_types=1);

namespace Tests\Integration\Models;

use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionStatus;
use App\Enums\ServiceSectionType;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\ServiceSection;
use App\Services\ChurchService\OutputEdgeAnswers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServiceSectionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_casts_section_type_and_status_to_enums(): void
    {
        $section = ServiceSection::factory()->create([
            'section_type' => ServiceSectionType::Sermon->value,
            'status' => ServiceSectionStatus::Identified->value,
            'publication_status' => ServiceSectionPublicationStatus::PendingApproval->value,
        ]);

        $this->assertInstanceOf(ServiceSectionType::class, $section->section_type);
        $this->assertSame(ServiceSectionType::Sermon, $section->section_type);
        $this->assertInstanceOf(ServiceSectionStatus::class, $section->status);
        $this->assertSame(ServiceSectionStatus::Identified, $section->status);
        $this->assertInstanceOf(ServiceSectionPublicationStatus::class, $section->publication_status);
        $this->assertSame(ServiceSectionPublicationStatus::PendingApproval, $section->publication_status);
    }

    #[Test]
    public function it_belongs_to_processing_log(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->create();
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
        ]);

        $this->assertSame($processingLog->id, $section->processingLog->id);
        $this->assertCount(1, $processingLog->serviceSections);
    }

    #[Test]
    public function it_belongs_to_church_service_item_including_soft_deleted_items(): void
    {
        $churchServiceItem = ChurchServiceItem::factory()->create();
        $section = ServiceSection::factory()->create([
            'church_service_item_id' => $churchServiceItem->id,
        ]);

        $churchServiceItem->delete();
        $section->refresh();

        $this->assertNotNull($section->churchServiceItem);
        $this->assertSame($churchServiceItem->id, $section->churchServiceItem?->id);
    }

    #[Test]
    public function it_belongs_to_published_sermon_when_linked(): void
    {
        $sermon = Sermon::factory()->create();
        $section = ServiceSection::factory()->create([
            'published_sermon_id' => $sermon->id,
            'publication_status' => ServiceSectionPublicationStatus::Published->value,
        ]);

        $this->assertSame($sermon->id, $section->publishedSermon?->id);
    }

    /**
     * The planner applies the operator's edges after every rule, so a clip cut before one was set is
     * stale although the section has not moved (10-24: nineteen recorded song starts).
     */
    #[Test]
    public function an_operator_edge_is_part_of_the_media_signature(): void
    {
        $section = ServiceSection::factory()->create(['section_type' => ServiceSectionType::Song->value, 'start_time' => 273.0, 'end_time' => 480.0, 'metadata' => []]);
        $withoutEdges = $section->mediaSignature();

        $section->metadata = [OutputEdgeAnswers::METADATA_KEY => ['start' => ['time' => 258.0, 'set_by_user_id' => 1, 'set_at' => '2026-10-24T11:00:00+00:00', 'from_time' => 273.0, 'section_span' => ['start_time' => 273.0, 'end_time' => 480.0]]]];
        $withStart = $section->mediaSignature();

        $this->assertNotSame($withoutEdges, $withStart);
        $this->assertSame(['start' => 258.0], $section->mediaSignaturePayload()['output_edges']);

        $section->metadata = [OutputEdgeAnswers::METADATA_KEY => ['start' => ['time' => 255.0, 'set_by_user_id' => 1, 'set_at' => '2026-10-24T12:00:00+00:00', 'from_time' => 273.0, 'section_span' => ['start_time' => 273.0, 'end_time' => 480.0]]]];

        $this->assertNotSame($withStart, $section->mediaSignature(), 'A changed answer re-cuts the clip.');
    }

    /** A section with no edge keeps the signature it had, so the change re-cuts no other clip. */
    #[Test]
    public function a_section_without_an_operator_edge_keeps_its_media_signature(): void
    {
        $section = ServiceSection::factory()->create(['section_type' => ServiceSectionType::Song->value, 'start_time' => 273.0, 'end_time' => 480.0, 'metadata' => []]);

        $this->assertSame(['section_type', 'start_time', 'end_time', 'media_processing'], array_keys($section->mediaSignaturePayload()));
    }
}
