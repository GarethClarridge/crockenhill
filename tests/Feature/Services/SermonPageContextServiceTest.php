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
        $this->service = app(SermonPageContextService::class);
    }

    /**
     * The §4.1b Scripture census found the page naming the service's first
     * reading on 157 of 438 sermons. The reading the media was cut with is
     * the one the congregation heard before the sermon.
     */
    #[Test]
    public function it_shows_the_reading_the_sermon_media_includes_not_the_first_reading(): void
    {
        $sermon = Sermon::factory()->create(['reference' => 'Philippians 2:1-4', 'livestream_processing_id' => null]);
        $log = MediaProcessingLog::factory()->livestream()->create([
            'processing_metadata' => ['sermon_extraction_plan' => ['segments' => [['start_time' => 1106.0, 'end_time' => 2914.0]]]],
        ]);

        $this->readingSection($log, 1, 548.0, 716.0, 'Psalm 72');
        $this->readingSection($log, 2, 1106.0, 1178.0, 'Philippians 2:5-11');
        $this->publishedSermonSection($log, $sermon, 3, 1372.0, 2914.0);

        $this->assertSame('Philippians 2:5-11', $this->service->build($sermon)['reading_reference']);
    }

    #[Test]
    public function it_shows_the_reading_matching_the_sermon_reference_when_the_media_includes_none(): void
    {
        $sermon = Sermon::factory()->create(['reference' => 'Philippians 2:5-8', 'livestream_processing_id' => null]);
        $log = MediaProcessingLog::factory()->livestream()->create([
            'processing_metadata' => ['sermon_extraction_plan' => ['segments' => [['start_time' => 1372.0, 'end_time' => 2914.0]]]],
        ]);

        $this->readingSection($log, 1, 548.0, 716.0, 'Psalm 72');
        $this->readingSection($log, 2, 900.0, 972.0, 'Philippians 2:5-11');
        $this->publishedSermonSection($log, $sermon, 3, 1372.0, 2914.0);

        $this->assertSame('Philippians 2:5-11', $this->service->build($sermon)['reading_reference']);
    }

    #[Test]
    public function it_shows_no_reading_when_none_is_in_the_media_or_matches_the_sermon_reference(): void
    {
        $sermon = Sermon::factory()->create(['reference' => 'John 3:16', 'livestream_processing_id' => null]);
        $log = MediaProcessingLog::factory()->livestream()->create([
            'processing_metadata' => ['sermon_extraction_plan' => ['segments' => [['start_time' => 1372.0, 'end_time' => 2914.0]]]],
        ]);

        $this->readingSection($log, 1, 548.0, 716.0, 'Psalm 72');
        $this->publishedSermonSection($log, $sermon, 2, 1372.0, 2914.0);

        $result = $this->service->build($sermon);

        $this->assertNull($result['reading_reference']);
        $this->assertNull($result['reading_url']);
    }

    private function readingSection(MediaProcessingLog $log, int $order, float $start, float $end, string $reference): ServiceSection
    {
        return ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::BibleReading,
            'section_order' => $order,
            'start_time' => $start,
            'end_time' => $end,
            'metadata' => new ServiceSectionMetadata(readingReference: $reference),
            'church_service_item_id' => null,
        ]);
    }

    private function publishedSermonSection(MediaProcessingLog $log, Sermon $sermon, int $order, float $start, float $end): ServiceSection
    {
        return ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Sermon,
            'section_order' => $order,
            'start_time' => $start,
            'end_time' => $end,
            'published_sermon_id' => $sermon->id,
            'metadata' => null,
            'church_service_item_id' => null,
        ]);
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
        $log = MediaProcessingLog::factory()->create(['sermon_id' => $sermon->id, 'processing_metadata' => ['sermon_extraction_plan' => ['segments' => [['start_time' => 0.0, 'end_time' => 3600.0]]]]]);

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
        $log = MediaProcessingLog::factory()->create(['sermon_id' => $sermon->id, 'processing_metadata' => ['sermon_extraction_plan' => ['segments' => [['start_time' => 0.0, 'end_time' => 3600.0]]]]]);

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
        $log = MediaProcessingLog::factory()->create(['sermon_id' => $sermon->id, 'processing_metadata' => ['sermon_extraction_plan' => ['segments' => [['start_time' => 0.0, 'end_time' => 3600.0]]]]]);

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
        $log = MediaProcessingLog::factory()->create(['sermon_id' => $sermon->id, 'processing_metadata' => ['sermon_extraction_plan' => ['segments' => [['start_time' => 0.0, 'end_time' => 3600.0]]]]]);

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
        $log = MediaProcessingLog::factory()->create(['sermon_id' => $sermon->id, 'processing_metadata' => ['sermon_extraction_plan' => ['segments' => [['start_time' => 0.0, 'end_time' => 3600.0]]]]]);

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
        $log = MediaProcessingLog::factory()->create(['processing_metadata' => ['sermon_extraction_plan' => ['segments' => [['start_time' => 0.0, 'end_time' => 3600.0]]]]]);
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
            'processing_metadata' => ['sermon_extraction_plan' => ['segments' => [['start_time' => 0.0, 'end_time' => 3600.0]]]],
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
        $log = MediaProcessingLog::factory()->create(['sermon_id' => $sermon->id, 'processing_metadata' => ['sermon_extraction_plan' => ['segments' => [['start_time' => 0.0, 'end_time' => 3600.0]]]]]);

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
        $log = MediaProcessingLog::factory()->create(['sermon_id' => $sermon->id, 'processing_metadata' => ['sermon_extraction_plan' => ['segments' => [['start_time' => 0.0, 'end_time' => 3600.0]]]]]);

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
