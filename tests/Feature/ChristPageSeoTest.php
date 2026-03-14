<?php

namespace Tests\Feature;

use App\Models\Sermon;
use App\Models\Preacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChristPageSeoTest extends TestCase
{
    use RefreshDatabase;

    public function test_christ_page_has_seo_metadata()
    {
        $response = $this->get('/christ');

        $response->assertStatus(200);
        $response->assertSee('<title>Christ - Crockenhill Baptist Church</title>', false);
        $response->assertSee('<meta name="description" content="Learn about Jesus Christ - who he is, why you need him, and what he has done for you. Find out more about the good news of Christianity at Crockenhill Baptist Church.">', false);
        $response->assertSee('<meta property="og:title" content="Christ - Crockenhill Baptist Church">', false);
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

        $desc = $sermon->meta_description;

        $response = $this->get('/christ/sermons/2024/01/the-way-of-peace');

        $response->assertStatus(200);
        $response->assertSee('<title>The Way of Peace | John Doe - Crockenhill Baptist Church</title>', false);
        $response->assertSee('<meta name="description" content="', false);
        $response->assertSee($sermon->title, false);
        $response->assertSee('<meta property="og:title" content="The Way of Peace | John Doe - Crockenhill Baptist Church">', false);
        $response->assertSee('<link rel="canonical" href="' . $sermon->public_url . '">', false);
    }

    public function test_preachers_index_page_has_seo_metadata_and_json_ld()
    {
        Preacher::factory()->create(['name' => 'Preacher One', 'slug' => 'preacher-one', 'is_active' => true]);
        Preacher::factory()->create(['name' => 'Preacher Two', 'slug' => 'preacher-two', 'is_active' => true]);

        $response = $this->get('/christ/sermons/preachers');

        $response->assertStatus(200);
        $response->assertSee('<title>Preachers - Crockenhill Baptist Church</title>', false);
        $response->assertSee('<meta name="description" content="Preachers at Crockenhill Baptist Church.">', false);
        $response->assertSee('<meta property="og:title" content="Preachers - Crockenhill Baptist Church">', false);

        // JSON-LD assertions
        $response->assertSee('"@type": "ItemList"', false);
        $response->assertSee('"numberOfItems": 2', false);
        $response->assertSee('"name": "Preacher One"', false);
        $response->assertSee('"name": "Preacher Two"', false);
        $response->assertSee('"jobTitle": "Preacher"', false);
    }
}
