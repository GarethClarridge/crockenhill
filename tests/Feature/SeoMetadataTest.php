<?php

namespace Tests\Feature;

use App\Models\Preacher;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoMetadataTest extends TestCase
{
    use RefreshDatabase;

    public function test_christ_page_has_seo_metadata()
    {
        $response = $this->get('/christ');

        $response->assertStatus(200);
        $response->assertSee('<title>Christ | Crockenhill Baptist Church</title>', false);
        $response->assertSee('<meta name="description" content="Learn about Jesus Christ - who he is, why you need him, and what he has done for you. Find out more about the good news of Christianity at Crockenhill Baptist Church.">', false);
        $response->assertSee('<meta property="og:title" content="Christ | Crockenhill Baptist Church">', false);
    }

    public function test_sermon_page_has_seo_metadata()
    {
        $preacher = Preacher::factory()->create(['name' => 'John Doe']);
        $sermon = Sermon::factory()->create([
            'title' => 'The Way of Peace',
            'preacher_id' => $preacher->id,
            'date' => '2024-01-01',
            'slug' => 'the-way-of-peace',
        ]);

        $response = $this->get('/christ/sermons/2024/01/the-way-of-peace');

        $response->assertStatus(200);
        $response->assertSee('<title>The Way of Peace | John Doe | Crockenhill Baptist Church</title>', false);
        $response->assertSee('<meta name="description" content="', false);
        $response->assertSee($sermon->title, false);
        $response->assertSee('<meta property="og:title" content="The Way of Peace | John Doe | Crockenhill Baptist Church">', false);
        $response->assertSee('<link rel="canonical" href="'.$sermon->canonical_url.'">', false);
    }

    public function test_preachers_index_page_has_seo_metadata_and_json_ld()
    {
        Preacher::factory()->create(['name' => 'Preacher One', 'slug' => 'preacher-one', 'is_active' => true]);
        Preacher::factory()->create(['name' => 'Preacher Two', 'slug' => 'preacher-two', 'is_active' => true]);

        $response = $this->get('/christ/sermons/preachers');

        $response->assertStatus(200);
        $response->assertSee('<title>Preachers | Crockenhill Baptist Church</title>', false);
        $response->assertSee('<meta name="description" content="Preachers at Crockenhill Baptist Church.">', false);
        $response->assertSee('<meta property="og:title" content="Preachers | Crockenhill Baptist Church">', false);

        // JSON-LD assertions - checking for structural presence
        $content = $response->getContent();
        $this->assertStringContainsString('"@type": "ItemList"', $content);
        $this->assertMatchesRegularExpression('/"numberOfItems":\s*2/', $content);
        $this->assertStringContainsString('"name": "Preacher One"', $content);
        $this->assertStringContainsString('"name": "Preacher Two"', $content);
        $this->assertStringContainsString('"jobTitle": "Preacher"', $content);
    }

    public function test_individual_preacher_page_has_seo_metadata(): void
    {
        $preacher = Preacher::factory()->create(['name' => 'John Doe']);

        $response = $this->get("/christ/sermons/preachers/{$preacher->slug}");

        $response->assertStatus(200);
        $response->assertSee('<title>Sermons by John Doe | Crockenhill Baptist Church</title>', false);
        $response->assertSee('<meta name="description" content="Browse all sermons preached by John Doe at Crockenhill Baptist Church.">', false);
    }
}
