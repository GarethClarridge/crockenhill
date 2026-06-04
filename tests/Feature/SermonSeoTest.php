<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SermonContentType;
use App\Enums\SermonService;
use App\Models\Preacher;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonSeoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        // These tests assert exact "numberOfItems" counts on the rendered SEO
        // JSON-LD, so they must start from an empty sermons table. A sibling
        // suite that commits rows outside RefreshDatabase's transaction (e.g. a
        // DatabaseMigrations DDL test) can otherwise leak sermons into the count.
        Sermon::query()->delete();
    }

    #[Test]
    public function sermon_index_has_item_list_structured_data_and_correct_title()
    {
        // Create some sermons to populate the index
        $preacher = Preacher::factory()->create(['name' => 'John Doe']);
        Sermon::factory()->count(3)->create([
            'preacher' => 'John Doe',
            'preacher_id' => $preacher->id,
            'content_type' => SermonContentType::Sermon,
        ]);

        $response = $this->get(route('sermons.index'));

        $response->assertStatus(200);
        $response->assertSee('Sermons | Crockenhill Baptist Church');
        $response->assertSee('"@type": "ItemList"', false);
        $response->assertSee('"numberOfItems": 3', false);
        $response->assertSee('"@type": "Article"', false);
        $response->assertSee('John Doe');
    }

    #[Test]
    public function legacy_all_sermons_route_redirects_to_the_canonical_archive()
    {
        $response = $this->get(route('sermons.all'));

        $response->assertRedirect(route('sermons.index'));
        $response->assertStatus(301);
    }

    #[Test]
    public function sermon_index_handles_missing_preacher_profile_gracefully()
    {
        // Create a sermon with only a preacher string, no ID/profile
        // Ensure it has a very recent date to appear in 'latest'
        Sermon::factory()->create([
            'preacher' => 'Guest Preacher',
            'preacher_id' => null,
            'content_type' => SermonContentType::Sermon,
            'date' => now(),
        ]);

        $response = $this->get(route('sermons.index'));

        $response->assertStatus(200);
        $response->assertSee('Guest Preacher');
        $response->assertSee('"@type": "Person"', false);
        $response->assertSee('"name": "Guest Preacher"', false);
    }

    #[Test]
    public function preacher_sermons_page_has_item_list_structured_data_and_breadcrumbs()
    {
        $preacher = Preacher::factory()->create(['name' => 'Preacher Name']);
        Sermon::factory()->count(2)->create([
            'preacher' => 'Preacher Name',
            'preacher_id' => $preacher->id,
            'content_type' => SermonContentType::Sermon,
        ]);

        $response = $this->get("/christ/sermons/preachers/{$preacher->slug}");

        $response->assertStatus(200);
        $response->assertSee('Sermons by Preacher Name | Crockenhill Baptist Church');
        $response->assertSee('"@type": "ItemList"', false);
        $response->assertSee('"numberOfItems": 2', false);
        // Breadcrumb check (provided by layout & controller context)
        $response->assertSee('"@type": "BreadcrumbList"', false);
        $response->assertSee('"name": "Christ"', false);
        $response->assertSee('"name": "Sermons"', false);
    }

    #[Test]
    public function series_sermons_page_has_item_list_structured_data_and_breadcrumbs()
    {
        Sermon::factory()->count(2)->create([
            'series' => 'My Great Series',
            'content_type' => SermonContentType::Sermon,
        ]);

        $response = $this->get('/christ/sermons/series/my-great-series');

        $response->assertStatus(200);
        $response->assertSee('Sermon Series: My Great Series | Crockenhill Baptist Church');
        $response->assertSee('"@type": "ItemList"', false);
        $response->assertSee('"numberOfItems": 2', false);
        // Breadcrumb check
        $response->assertSee('"@type": "BreadcrumbList"', false);
        $response->assertSee('"name": "Christ"', false);
    }

    #[Test]
    public function service_sermons_page_has_item_list_structured_data_and_breadcrumbs()
    {
        Sermon::factory()->count(2)->create([
            'service' => SermonService::Morning,
            'content_type' => SermonContentType::Sermon,
        ]);

        $response = $this->get('/christ/sermons/morning');

        $response->assertStatus(200);
        $response->assertSee('Sunday Morning Services | Crockenhill Baptist Church');
        $response->assertSee('"@type": "ItemList"', false);
        $response->assertSee('"numberOfItems": 2', false);
        // Breadcrumb check
        $response->assertSee('"@type": "BreadcrumbList"', false);
        $response->assertSee('"name": "Christ"', false);
    }

    #[Test]
    public function preacher_profile_has_person_structured_data()
    {
        $preacher = Preacher::factory()->create([
            'name' => 'Dr. Martin Lloyd-Jones',
            'bio' => 'A famous preacher with a long bio.',
            'image_path' => 'preachers/mlj.jpg',
        ]);

        $response = $this->get("/christ/sermons/preachers/{$preacher->slug}");

        $response->assertStatus(200);
        $response->assertSee('"@type": "Person"', false);
        $response->assertSee('"name": "Dr. Martin Lloyd-Jones"', false);
        $response->assertSee('"description": "A famous preacher with a long bio."', false);
        $response->assertSee('"image": "http://localhost/storage/preachers/mlj.jpg"', false);
        $response->assertSee('"worksFor": {', false);
        $response->assertSee('"@type": "Organization"', false);
        $response->assertSee('"name": "Crockenhill Baptist Church"', false);
    }
}
