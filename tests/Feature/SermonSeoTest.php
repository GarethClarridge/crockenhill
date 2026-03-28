<?php

namespace Tests\Feature;

use App\Enums\SermonContentType;
use App\Enums\SermonService;
use App\Models\Preacher;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonSeoTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
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
    public function all_sermons_page_has_item_list_structured_data_and_correct_title()
    {
        // Create some sermons to populate the index
        $preacher = Preacher::factory()->create(['name' => 'Jane Doe']);
        Sermon::factory()->count(3)->create([
            'preacher' => 'Jane Doe',
            'preacher_id' => $preacher->id,
            'content_type' => SermonContentType::Sermon,
        ]);

        $response = $this->get(route('sermons.all'));

        $response->assertStatus(200);
        $response->assertSee('All Sermons | Crockenhill Baptist Church');
        $response->assertSee('"@type": "ItemList"', false);
        $response->assertSee('"numberOfItems": 3', false);
        $response->assertSee('"@type": "Article"', false);
        $response->assertSee('Jane Doe');
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

        // Person schema check
        $response->assertSee('"@type": "Person"', false);
        $response->assertSee('"name": "Preacher Name"', false);
        $response->assertSee('"@type": "Organization"', false);
        $response->assertSee('Crockenhill Baptist Church');
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
            'service' => SermonService::MORNING,
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
}
