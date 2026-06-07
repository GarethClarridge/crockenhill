<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Data\ServiceSectionMetadata;
use App\Enums\ServiceSectionType;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\ServiceSection;
use App\Services\Sermon\SermonPageContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonPageContextServiceTest extends TestCase
{
    use RefreshDatabase;

    private SermonPageContextService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SermonPageContextService;
    }

    #[Test]
    public function it_returns_null_values_when_no_processing_log_exists(): void
    {
        $sermon = Sermon::factory()->create([
            'livestream_processing_id' => null,
        ]);

        $result = $this->service->build($sermon);

        $this->assertNull($result['reading_reference']);
        $this->assertNull($result['reading_url']);
    }

    #[Test]
    public function it_returns_null_values_when_no_reading_section_exists(): void
    {
        $sermon = Sermon::factory()->create();
        $log = MediaProcessingLog::factory()->create(['sermon_id' => $sermon->id]);

        // Create a non-reading section
        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Sermon,
        ]);

        $result = $this->service->build($sermon);

        $this->assertNull($result['reading_reference']);
        $this->assertNull($result['reading_url']);
    }

    #[Test]
    public function it_resolves_reference_from_section_metadata(): void
    {
        $sermon = Sermon::factory()->create();
        $log = MediaProcessingLog::factory()->create(['sermon_id' => $sermon->id]);

        $metadata = new ServiceSectionMetadata(readingReference: 'John 3:16');

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::BibleReading,
            'metadata' => $metadata,
            'title' => 'Other Title',
        ]);

        $result = $this->service->build($sermon);

        $this->assertEquals('John 3:16', $result['reading_reference']);
        $this->assertStringContainsString('John%203%3A16', $result['reading_url']);
    }

    #[Test]
    public function it_resolves_reference_from_church_service_item_title(): void
    {
        $sermon = Sermon::factory()->create();
        $log = MediaProcessingLog::factory()->create(['sermon_id' => $sermon->id]);

        $item = ChurchServiceItem::factory()->create(['title' => 'Genesis 1:1']);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::BibleReading,
            'church_service_item_id' => $item->id,
            'metadata' => null,
            'title' => 'Other Title',
        ]);

        $result = $this->service->build($sermon);

        $this->assertEquals('Genesis 1:1', $result['reading_reference']);
    }

    #[Test]
    public function it_resolves_reference_from_section_title(): void
    {
        $sermon = Sermon::factory()->create();
        $log = MediaProcessingLog::factory()->create(['sermon_id' => $sermon->id]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::BibleReading,
            'church_service_item_id' => null,
            'metadata' => null,
            'title' => 'Psalm 23',
        ]);

        $result = $this->service->build($sermon);

        $this->assertEquals('Psalm 23', $result['reading_reference']);
    }

    #[Test]
    public function it_respects_priority_order_metadata_first(): void
    {
        $sermon = Sermon::factory()->create();
        $log = MediaProcessingLog::factory()->create(['sermon_id' => $sermon->id]);

        $item = ChurchServiceItem::factory()->create(['title' => 'Item Title']);
        $metadata = new ServiceSectionMetadata(readingReference: 'Metadata Reference');

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::BibleReading,
            'church_service_item_id' => $item->id,
            'metadata' => $metadata,
            'title' => 'Section Title',
        ]);

        $result = $this->service->build($sermon);

        $this->assertEquals('Metadata Reference', $result['reading_reference']);
    }

    #[Test]
    public function it_resolves_via_published_service_section(): void
    {
        $log = MediaProcessingLog::factory()->create();
        $sermon = Sermon::factory()->create();

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::BibleReading,
            'title' => 'Isaiah 53',
            'metadata' => null,
            'church_service_item_id' => null,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Sermon,
            'published_sermon_id' => $sermon->id,
            'metadata' => null,
            'church_service_item_id' => null,
        ]);

        $result = $this->service->build($sermon);

        $this->assertEquals('Isaiah 53', $result['reading_reference']);
    }

    #[Test]
    public function it_resolves_via_livestream_processing_id(): void
    {
        $log = MediaProcessingLog::factory()->create([
            'processing_id' => 'livestream-123',
        ]);

        $sermon = Sermon::factory()->create([
            'livestream_processing_id' => 'livestream-123',
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::BibleReading,
            'title' => 'Romans 8',
            'metadata' => null,
            'church_service_item_id' => null,
        ]);

        $result = $this->service->build($sermon);

        $this->assertEquals('Romans 8', $result['reading_reference']);
    }

    #[Test]
    public function it_generates_correct_bible_gateway_url(): void
    {
        $sermon = Sermon::factory()->create();
        $log = MediaProcessingLog::factory()->create(['sermon_id' => $sermon->id]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::BibleReading,
            'title' => 'John 3:16-17',
            'metadata' => null,
            'church_service_item_id' => null,
        ]);

        $result = $this->service->build($sermon);

        $expected = 'https://www.biblegateway.com/passage/?search=John%203%3A16-17&version=NIVUK';
        $this->assertEquals($expected, $result['reading_url']);
    }

    #[Test]
    public function it_trims_references(): void
    {
        $sermon = Sermon::factory()->create();
        $log = MediaProcessingLog::factory()->create(['sermon_id' => $sermon->id]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::BibleReading,
            'title' => '  Psalm 119  ',
            'metadata' => null,
            'church_service_item_id' => null,
        ]);

        $result = $this->service->build($sermon);

        $this->assertEquals('Psalm 119', $result['reading_reference']);
    }
}
