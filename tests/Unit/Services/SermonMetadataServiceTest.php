<?php

namespace Tests\Unit\Services;

use App\Enums\SermonService;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\SermonMetadataService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonMetadataServiceTest extends TestCase
{
    use RefreshDatabase;

    private SermonMetadataService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SermonMetadataService;
    }

    // --- extractFromFilename() ---

    #[Test]
    public function it_extracts_date_from_yyyy_mm_dd_format(): void
    {
        $metadata = $this->service->extractFromFilename('2026-01-15_sermon.mp3');

        $this->assertEquals('2026-01-15', $metadata->date->toDateString());
    }

    #[Test]
    public function it_extracts_date_from_dd_mm_yyyy_format(): void
    {
        $metadata = $this->service->extractFromFilename('15-01-2026_sermon.mp3');

        $this->assertEquals('2026-01-15', $metadata->date->toDateString());
    }

    #[Test]
    public function it_extracts_date_from_yyyy_underscore_mm_dd_format(): void
    {
        $metadata = $this->service->extractFromFilename('2026_02_10_morning.mp3');

        $this->assertEquals('2026-02-10', $metadata->date->toDateString());
    }

    #[Test]
    public function it_extracts_date_from_dd_underscore_mm_yyyy_format(): void
    {
        $metadata = $this->service->extractFromFilename('10_02_2026_evening.mp3');

        $this->assertEquals('2026-02-10', $metadata->date->toDateString());
    }

    #[Test]
    public function it_falls_back_to_today_when_no_date_in_filename(): void
    {
        $metadata = $this->service->extractFromFilename('sermon_recording.mp3');

        $this->assertEquals(Carbon::today()->toDateString(), $metadata->date->toDateString());
    }

    #[Test]
    public function it_detects_evening_service_from_filename(): void
    {
        $metadata = $this->service->extractFromFilename('2026-01-15_evening_sermon.mp3');

        $this->assertEquals(SermonService::EVENING, $metadata->service);
    }

    #[Test]
    public function it_detects_evening_service_from_pm_indicator(): void
    {
        $metadata = $this->service->extractFromFilename('2026-01-15_6pm_sermon.mp3');

        $this->assertEquals(SermonService::EVENING, $metadata->service);
    }

    #[Test]
    public function it_detects_morning_service_from_filename(): void
    {
        $metadata = $this->service->extractFromFilename('2026-01-15_morning_sermon.mp3');

        $this->assertEquals(SermonService::MORNING, $metadata->service);
    }

    #[Test]
    public function it_detects_morning_service_from_am_indicator(): void
    {
        $metadata = $this->service->extractFromFilename('2026-01-15_10am_sermon.mp3');

        $this->assertEquals(SermonService::MORNING, $metadata->service);
    }

    #[Test]
    public function it_defaults_to_morning_service_when_no_indicator(): void
    {
        $metadata = $this->service->extractFromFilename('2026-01-15_sermon.mp3');

        $this->assertEquals(SermonService::MORNING, $metadata->service);
    }

    #[Test]
    public function it_sets_filename_from_basename(): void
    {
        $metadata = $this->service->extractFromFilename('some/path/2026-01-15_sermon.mp3');

        $this->assertEquals('2026-01-15_sermon.mp3', $metadata->filename);
    }

    #[Test]
    public function it_preserves_original_name(): void
    {
        $metadata = $this->service->extractFromFilename('original_name.mp3');

        $this->assertEquals('original_name.mp3', $metadata->originalName);
    }

    // --- generateFallbackData() ---

    #[Test]
    public function it_generates_fallback_data_for_untitled_sermon(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'Untitled Sermon',
            'date' => Carbon::create(2026, 1, 15),
            'service' => SermonService::MORNING->value,
        ]);
        $log = MediaProcessingLog::factory()->audio()->failed()->create([
            'sermon_id' => $sermon->id,
        ]);

        $fallback = $this->service->generateFallbackData($sermon, $log);

        $this->assertArrayHasKey('title', $fallback);
        $this->assertArrayHasKey('slug', $fallback);
        $this->assertNull($fallback['series']);
        $this->assertNull($fallback['reference']);
        $this->assertEquals(['Main Message'], $fallback['points']);
        $this->assertStringContainsString('January 15, 2026', $fallback['title']);
        $this->assertStringContainsString('Morning', $fallback['title']);
    }

    #[Test]
    public function it_preserves_existing_title_if_adequate(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'The Good Shepherd',
            'date' => Carbon::create(2026, 1, 15),
        ]);
        $log = MediaProcessingLog::factory()->audio()->failed()->create([
            'sermon_id' => $sermon->id,
        ]);

        $fallback = $this->service->generateFallbackData($sermon, $log);

        $this->assertEquals('The Good Shepherd', $fallback['title']);
    }

    #[Test]
    public function it_replaces_generic_sermon_dash_title(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'Sermon - Morning',
            'date' => Carbon::create(2026, 2, 10),
            'service' => SermonService::EVENING->value,
        ]);
        $log = MediaProcessingLog::factory()->audio()->failed()->create([
            'sermon_id' => $sermon->id,
        ]);

        $fallback = $this->service->generateFallbackData($sermon, $log);

        $this->assertStringContainsString('February 10, 2026', $fallback['title']);
    }

    // --- generateUniqueSlug() ---

    #[Test]
    public function it_generates_slug_from_title(): void
    {
        $sermon = Sermon::factory()->create();

        $slug = $this->service->generateUniqueSlug('The Good Shepherd', $sermon->id);

        $this->assertEquals('the-good-shepherd', $slug);
    }

    #[Test]
    public function it_generates_unique_slug_avoiding_conflicts(): void
    {
        Sermon::factory()->create(['slug' => 'the-good-shepherd']);
        $newSermon = Sermon::factory()->create();

        $slug = $this->service->generateUniqueSlug('The Good Shepherd', $newSermon->id);

        $this->assertEquals('the-good-shepherd-1', $slug);
    }

    #[Test]
    public function it_allows_same_slug_for_same_sermon(): void
    {
        $sermon = Sermon::factory()->create(['slug' => 'the-good-shepherd']);

        $slug = $this->service->generateUniqueSlug('The Good Shepherd', $sermon->id);

        $this->assertEquals('the-good-shepherd', $slug);
    }

    #[Test]
    public function it_increments_slug_suffix_for_multiple_conflicts(): void
    {
        Sermon::factory()->create(['slug' => 'psalm-23']);
        Sermon::factory()->create(['slug' => 'psalm-23-1']);
        $newSermon = Sermon::factory()->create();

        $slug = $this->service->generateUniqueSlug('Psalm 23', $newSermon->id);

        $this->assertEquals('psalm-23-2', $slug);
    }
}
