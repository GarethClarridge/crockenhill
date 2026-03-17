<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SermonContentType;
use App\Enums\SermonService;
use App\Enums\ServiceSectionType;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Models\ServiceSection;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonPagesTest extends TestCase
{
    use DatabaseTransactions;

    private int $initialOutputBufferLevel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initialOutputBufferLevel = ob_get_level();

        // Clear any existing sermons to ensure test isolation
        Sermon::query()->delete();

        // Create test sermons for each service type
        Sermon::factory()->create([
            'title' => 'Morning Test Sermon',
            'service' => SermonService::MORNING->value,
            'slug' => 'morning-test-sermon',
        ]);
        Sermon::factory()->create([
            'title' => 'Evening Test Sermon',
            'service' => SermonService::EVENING->value,
            'slug' => 'evening-test-sermon',
        ]);
        Sermon::factory()->create([
            'title' => 'Other Test Sermon',
            'service' => SermonService::OTHER->value,
            'slug' => 'other-test-sermon',
        ]);
    }

    protected function tearDown(): void
    {
        // Guard against leaked output buffers in rendered views/components.
        while (ob_get_level() > $this->initialOutputBufferLevel) {
            ob_end_clean();
        }

        parent::tearDown();
    }

    #[Test]
    public function sermon_index_page_renders(): void
    {
        $response = $this->get('/christ/sermons');
        $response->assertStatus(200);
        $response->assertSee('Morning Test Sermon');
        $response->assertSee('Evening Test Sermon');
        $response->assertSee('Other Test Sermon');

        // Additional assertion to ensure the page contains expected content
        $this->assertStringContainsString('sermons', $response->getContent());
    }

    #[Test]
    public function sermon_show_page_renders(): void
    {
        $sermon = Sermon::first();
        $url = "/christ/sermons/{$sermon->slug}";
        $response = $this->get($url);
        $response->assertStatus(200);
        $response->assertSee($sermon->title);
        $response->assertSee($sermon->service->label());
    }

    #[Test]
    public function sermon_transcript_strips_script_tags_from_markdown(): void
    {
        Storage::fake('local');

        $transcriptPath = 'transcripts/security-script-tag.md';
        Storage::put($transcriptPath, '<script>alert("transcript-xss")</script>'."\n\n".'Safe transcript content.');

        $sermon = Sermon::factory()->create([
            'slug' => 'security-script-tag-sermon',
            'transcript_file_path' => $transcriptPath,
        ]);

        $response = $this->get("/christ/sermons/{$sermon->slug}");

        $response->assertStatus(200);
        $response->assertSee('Safe transcript content.');
        $response->assertDontSee('<script>alert("transcript-xss")</script>', false);
    }

    #[Test]
    public function sermon_transcript_blocks_javascript_links_from_markdown(): void
    {
        Storage::fake('local');

        $transcriptPath = 'transcripts/security-javascript-link.md';
        Storage::put($transcriptPath, '[Click me](javascript:alert("transcript-link"))');

        $sermon = Sermon::factory()->create([
            'slug' => 'security-javascript-link-sermon',
            'transcript_file_path' => $transcriptPath,
        ]);

        $response = $this->get("/christ/sermons/{$sermon->slug}");

        $response->assertStatus(200);
        $response->assertSee('Click me');
        $response->assertDontSee('javascript:alert("transcript-link")', false);
    }

    #[Test]
    public function sermon_page_handles_missing_transcript_file_without_crashing(): void
    {
        // Fake all disks that TranscriptStorageService iterates over so no network calls are made
        Storage::fake('local');
        Storage::fake('public');
        Storage::fake('do_spaces');

        $sermon = Sermon::factory()->create([
            'slug' => 'missing-transcript-sermon',
            'transcript_file_path' => 'transcripts/missing-transcript.md',
        ]);

        $response = $this->get("/christ/sermons/{$sermon->slug}");

        $response->assertStatus(200);
        $response->assertDontSee('Automated transcript (may contain errors)');
    }

    #[Test]
    public function sermon_all_page_renders(): void
    {
        $response = $this->get('/christ/sermons/all');
        $response->assertStatus(200);
        $response->assertSee('Morning Test Sermon');
        $response->assertSee('Evening Test Sermon');
        $response->assertSee('Other Test Sermon');
    }

    #[Test]
    public function grouped_sermon_lists_render_date_heading_above_cards_grid(): void
    {
        $response = $this->get('/christ/sermons');

        $response->assertStatus(200);
        $response->assertSeeInOrder([
            '<h2 id=',
            'text-3xl sm:text-4xl',
            '<div class="grid items-start justify-center gap-2 [grid-template-columns:repeat(auto-fit,minmax(min(100%,19rem),19rem))]">',
        ], false);
    }

    #[Test]
    public function sermon_service_page_renders(): void
    {
        foreach (SermonService::cases() as $service) {
            $response = $this->get("/christ/sermons/{$service->value}");
            $response->assertStatus(200);
            $response->assertSee($service->label());
        }
    }

    #[Test]
    public function sermon_preacher_page_renders(): void
    {
        $preacher = Preacher::factory()->create(['name' => 'Test Preacher', 'slug' => 'test-preacher']);
        Sermon::factory()->create(['preacher' => 'Test Preacher', 'preacher_id' => $preacher->id]);

        $response = $this->get('/christ/sermons/preachers/test-preacher');
        $response->assertStatus(200);
        $response->assertSee('Test Preacher');
    }

    #[Test]
    public function sermon_show_page_uses_single_preacher_url_prefix(): void
    {
        $preacher = Preacher::factory()->create(['name' => 'Test Preacher', 'slug' => 'test-preacher']);
        $sermon = Sermon::factory()->create([
            'preacher' => 'Test Preacher',
            'preacher_id' => $preacher->id,
        ]);

        $response = $this->get("/christ/sermons/{$sermon->slug}");

        $response->assertStatus(200);
        $response->assertSee('href="/christ/sermons/preachers/test-preacher"', false);
        $response->assertDontSee('/christ/sermons//christ/sermons/preachers/', false);
    }

    #[Test]
    public function sermon_series_page_renders(): void
    {
        $sermon = Sermon::factory()->inSeries('Test Series')->create();
        $response = $this->get('/christ/sermons/series/test-series');
        $response->assertStatus(200);
        $response->assertSee('Test Series');
    }

    #[Test]
    public function sermon_show_with_date_route_renders(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'Date Based Sermon',
            'slug' => 'date-based-sermon',
            'date' => '2024-03-15',
        ]);

        $response = $this->get('/christ/sermons/2024/03/date-based-sermon');

        $response->assertStatus(200);
        $response->assertSee('Date Based Sermon');
    }

    #[Test]
    public function sermon_show_with_date_route_returns_404_on_year_mismatch(): void
    {
        $sermon = Sermon::factory()->create([
            'slug' => 'year-mismatch-sermon',
            'date' => '2024-03-15',
        ]);

        $response = $this->get('/christ/sermons/2023/03/year-mismatch-sermon');

        $response->assertStatus(404);
    }

    #[Test]
    public function sermon_show_with_date_route_returns_404_on_month_mismatch(): void
    {
        $sermon = Sermon::factory()->create([
            'slug' => 'month-mismatch-sermon',
            'date' => '2024-03-15',
        ]);

        $response = $this->get('/christ/sermons/2024/04/month-mismatch-sermon');

        $response->assertStatus(404);
    }

    #[Test]
    public function sermon_preachers_index_page_renders(): void
    {
        Preacher::factory()->create(['name' => 'John Owen', 'slug' => 'john-owen', 'is_active' => true]);
        Preacher::factory()->create(['name' => 'Charles Spurgeon', 'slug' => 'charles-spurgeon', 'is_active' => true]);

        $response = $this->get('/christ/sermons/preachers');

        $response->assertStatus(200);
        $response->assertSee('Preachers');
        $response->assertSee('John Owen');
        $response->assertSee('Charles Spurgeon');
    }

    #[Test]
    public function sermon_page_displays_service_reading_separately_with_bible_gateway_link(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'processing_id' => 'processing-reading',
        ]);

        $sermon = Sermon::factory()->create([
            'title' => 'Sermon With Reading',
            'slug' => 'sermon-with-reading',
            'reference' => 'Romans 8:1-17',
            'livestream_processing_id' => $processingLog->processing_id,
        ]);

        $readingItem = ChurchServiceItem::factory()->create([
            'title' => 'Luke 15:1-32',
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => $readingItem->id,
            'section_type' => ServiceSectionType::BIBLE_READING->value,
            'section_order' => 1,
            'metadata' => [
                'reading_reference' => 'Luke 15:1-32',
            ],
        ]);

        $response = $this->get("/christ/sermons/{$sermon->slug}");

        $response->assertStatus(200);
        $response->assertSee('Passage');
        $response->assertSee('Romans 8:1-17');
        $response->assertSee('Reading');
        $response->assertSee('Luke 15:1-32');
        $response->assertSee('https://www.biblegateway.com/passage/?search=Luke%2015%3A1-32&amp;version=NIVUK', false);
    }

    #[Test]
    public function sermon_page_hides_service_reading_when_none_exists(): void
    {
        $sermon = Sermon::factory()->create([
            'slug' => 'sermon-without-reading',
        ]);

        $response = $this->get("/christ/sermons/{$sermon->slug}");

        $response->assertStatus(200);
        $response->assertDontSee('>Reading<', false);
        $response->assertDontSee('biblegateway.com/passage/?search=', false);
    }

    #[Test]
    public function public_sermon_browse_pages_exclude_childrens_talks(): void
    {
        $preacher = Preacher::factory()->create(['name' => 'Browse Preacher', 'slug' => 'browse-preacher']);

        Sermon::factory()->create([
            'title' => 'Public Browse Sermon',
            'series' => 'Browse Series',
            'service' => SermonService::MORNING->value,
            'preacher' => $preacher->name,
            'preacher_id' => $preacher->id,
            'content_type' => SermonContentType::Sermon,
        ]);

        Sermon::factory()->create([
            'title' => "Hidden Children's Talk",
            'series' => 'Browse Series',
            'service' => SermonService::MORNING->value,
            'preacher' => $preacher->name,
            'preacher_id' => $preacher->id,
            'content_type' => SermonContentType::ChildrensTalk,
        ]);

        $this->get('/christ/sermons')
            ->assertStatus(200)
            ->assertSee('Public Browse Sermon')
            ->assertDontSee("Hidden Children's Talk");

        $this->get('/christ/sermons/all')
            ->assertStatus(200)
            ->assertSee('Public Browse Sermon')
            ->assertDontSee("Hidden Children's Talk");

        $this->get('/christ/sermons/morning')
            ->assertStatus(200)
            ->assertSee('Public Browse Sermon')
            ->assertDontSee("Hidden Children's Talk");

        $this->get('/christ/sermons/preachers/browse-preacher')
            ->assertStatus(200)
            ->assertSee('Public Browse Sermon')
            ->assertDontSee("Hidden Children's Talk");

        $this->get('/christ/sermons/series/browse-series')
            ->assertStatus(200)
            ->assertSee('Public Browse Sermon')
            ->assertDontSee("Hidden Children's Talk");
    }
}
