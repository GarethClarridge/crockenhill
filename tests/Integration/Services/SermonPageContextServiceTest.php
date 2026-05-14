<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Enums\ServiceSectionType;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\ServiceSection;
use App\Services\SermonPageContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonPageContextServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_resolves_reading_reference_from_published_section_processing_context(): void
    {
        $sermon = Sermon::factory()->create();
        $processingLog = MediaProcessingLog::factory()->livestream()->create();

        $publishedSection = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'published_sermon_id' => $sermon->id,
            'section_type' => ServiceSectionType::SERMON->value,
        ]);

        $readingItem = ChurchServiceItem::factory()->create([
            'title' => 'John 10:1-18',
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => $readingItem->id,
            'section_type' => ServiceSectionType::BIBLE_READING->value,
            'section_order' => $publishedSection->section_order + 1,
            'metadata' => [
                'reading_reference' => 'John 10:1-18',
            ],
        ]);

        $context = app(SermonPageContextService::class)->build($sermon);

        $this->assertSame('John 10:1-18', $context['reading_reference']);
        $this->assertSame(
            'https://www.biblegateway.com/passage/?search=John%2010%3A1-18&version=NIVUK',
            $context['reading_url']
        );
    }

    #[Test]
    public function it_returns_null_context_when_no_reading_section_exists(): void
    {
        MediaProcessingLog::factory()->livestream()->create([
            'processing_id' => 'processing-123',
        ]);

        $sermon = Sermon::factory()->create([
            'livestream_processing_id' => 'processing-123',
        ]);

        $context = app(SermonPageContextService::class)->build($sermon);

        $this->assertNull($context['reading_reference']);
        $this->assertNull($context['reading_url']);
    }

    #[Test]
    public function it_falls_back_to_processing_logs_when_no_published_or_livestream_processing_link_exists(): void
    {
        $sermon = Sermon::factory()->create([
            'livestream_processing_id' => null,
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->withSermon($sermon)->create();

        $readingItem = ChurchServiceItem::factory()->create([
            'title' => 'Psalm 121',
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => $readingItem->id,
            'section_type' => ServiceSectionType::BIBLE_READING->value,
            'metadata' => [
                'classification_mode' => 'openlp_aligned',
            ],
            'title' => null,
        ]);

        $context = app(SermonPageContextService::class)->build($sermon);

        $this->assertSame('Psalm 121', $context['reading_reference']);
        $this->assertSame(
            'https://www.biblegateway.com/passage/?search=Psalm%20121&version=NIVUK',
            $context['reading_url']
        );
    }

    #[Test]
    public function it_does_not_load_non_reading_sections_when_resolving_reading_reference(): void
    {
        $sermon = Sermon::factory()->create(['livestream_processing_id' => null]);

        $processingLog = MediaProcessingLog::factory()->livestream()->withSermon($sermon)->create();

        $readingItem = ChurchServiceItem::factory()->create(['title' => 'Acts 2:1-12']);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => $readingItem->id,
            'section_type' => ServiceSectionType::BIBLE_READING->value,
            'section_order' => 1,
            'title' => null,
        ]);

        // Create several non-reading sections that should not be fetched
        ServiceSection::factory()->count(5)->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::SERMON->value,
        ]);

        $sectionQueries = [];

        DB::listen(function ($query) use (&$sectionQueries): void {
            if (str_contains($query->sql, 'service_sections')) {
                $sectionQueries[] = $query->sql;
            }
        });

        $context = app(SermonPageContextService::class)->build($sermon);

        $this->assertSame('Acts 2:1-12', $context['reading_reference']);

        // The service must filter by section_type at the database level —
        // at least one query targeting service_sections must include the section_type constraint.
        $typeConstrained = collect($sectionQueries)->contains(
            fn (string $sql): bool => str_contains($sql, 'section_type')
        );

        $this->assertTrue(
            $typeConstrained,
            'Expected at least one service_sections query to filter by section_type at the DB level, but none did.'
        );
    }
}
