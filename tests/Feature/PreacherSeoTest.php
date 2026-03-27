<?php

namespace Tests\Feature;

use App\Models\Preacher;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class PreacherSeoTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function it_renders_person_schema_on_preacher_page()
    {
        $preacher = Preacher::factory()->create([
            'name' => 'John Doe',
            'bio' => 'A faithful preacher of the word.',
            'image_path' => 'preachers/john-doe.jpg',
        ]);

        $sermon = Sermon::factory()->create(['preacher_id' => $preacher->id]);

        $response = $this->get("/christ/sermons/preachers/{$preacher->slug}");

        $response->assertStatus(200);
        $response->assertSee('https://schema.org');
        $response->assertSee('Person');
        $response->assertSee('John Doe');
        $response->assertSee('A faithful preacher of the word.');
        $response->assertSee('john-doe.jpg');
    }

    #[Test]
    public function it_uses_preacher_bio_for_meta_description()
    {
        $preacher = Preacher::factory()->create([
            'bio' => 'This is a custom bio for SEO testing.',
        ]);

        $sermon = Sermon::factory()->create(['preacher_id' => $preacher->id]);

        $response = $this->get("/christ/sermons/preachers/{$preacher->slug}");

        $response->assertSee('<meta name="description" content="This is a custom bio for SEO testing.">', false);
        $response->assertSee('property="og:description" content="This is a custom bio for SEO testing."', false);
    }
}
