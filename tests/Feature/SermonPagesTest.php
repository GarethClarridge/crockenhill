<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SermonService;
use App\Models\Preacher;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class SermonPagesTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

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

    public function test_sermon_all_page_renders(): void
    {
        $response = $this->get('/christ/sermons/all');
        $response->assertStatus(200);
        $response->assertSee('Morning Test Sermon');
        $response->assertSee('Evening Test Sermon');
        $response->assertSee('Other Test Sermon');
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

    public function test_sermon_series_page_renders(): void
    {
        $sermon = Sermon::first();
        if ($sermon->series) {
            $response = $this->get('/christ/sermons/series/'.Str::slug($sermon->series));
            $response->assertStatus(200);
            $response->assertSee($sermon->series);
        } else {
            $this->assertTrue(true); // No series to test
        }
    }
}
