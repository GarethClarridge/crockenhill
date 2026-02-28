<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\ServiceSectionStatus;
use App\Enums\ServiceSectionType;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
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
            'section_type' => ServiceSectionType::SERMON->value,
            'status' => ServiceSectionStatus::IDENTIFIED->value,
        ]);

        $this->assertInstanceOf(ServiceSectionType::class, $section->section_type);
        $this->assertSame(ServiceSectionType::SERMON, $section->section_type);
        $this->assertInstanceOf(ServiceSectionStatus::class, $section->status);
        $this->assertSame(ServiceSectionStatus::IDENTIFIED, $section->status);
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
}
