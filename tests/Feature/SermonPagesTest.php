<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SermonService;
use App\Models\Preacher;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
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

    public function test_sermon_index_page_renders(): void
    {
        $response = $this->get('/christ/sermons');
        $response->assertStatus(200);
        $response->assertSee('Morning Test Sermon');
        $response->assertSee('Evening Test Sermon');
        $response->assertSee('Other Test Sermon');

        // Additional assertion to ensure the page contains expected content
        $this->assertStringContainsString('sermons', $response->getContent());
    }

    public function test_sermon_show_page_renders(): void
    {
        $sermon = Sermon::first();
        $url = "/christ/sermons/{$sermon->slug}";
        $response = $this->get($url);
        $response->assertStatus(200);
        $response->assertSee($sermon->title);
        $response->assertSee($sermon->service->label());
    }

    public function test_sermon_transcript_strips_script_tags_from_markdown(): void
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

    public function test_sermon_transcript_blocks_javascript_links_from_markdown(): void
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

    public function test_sermon_all_page_renders(): void
    {
        $response = $this->get('/christ/sermons/all');
        $response->assertStatus(200);
        $response->assertSee('Morning Test Sermon');
        $response->assertSee('Evening Test Sermon');
        $response->assertSee('Other Test Sermon');
    }

    public function test_grouped_sermon_lists_render_date_heading_above_cards_grid(): void
    {
        $response = $this->get('/christ/sermons');

        $response->assertStatus(200);
        $response->assertSeeInOrder([
            '<h2 id=',
            'text-3xl sm:text-4xl',
            '<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-2 justify-center items-start">',
        ], false);
    }

    public function test_sermon_service_page_renders(): void
    {
        foreach (SermonService::cases() as $service) {
            $response = $this->get("/christ/sermons/{$service->value}");
            $response->assertStatus(200);
            $response->assertSee($service->label());
        }
    }

    public function test_sermon_preacher_page_renders(): void
    {
        $preacher = Preacher::factory()->create(['name' => 'Test Preacher', 'slug' => 'test-preacher']);
        Sermon::factory()->create(['preacher' => 'Test Preacher', 'preacher_id' => $preacher->id]);

        $response = $this->get('/christ/sermons/preachers/test-preacher');
        $response->assertStatus(200);
        $response->assertSee('Test Preacher');
    }

    public function test_sermon_show_page_uses_single_preacher_url_prefix(): void
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

    public function test_sermon_series_page_renders(): void
    {
        $sermon = Sermon::factory()->inSeries('Test Series')->create();
        $response = $this->get('/christ/sermons/series/test-series');
        $response->assertStatus(200);
        $response->assertSee('Test Series');
    }
}
