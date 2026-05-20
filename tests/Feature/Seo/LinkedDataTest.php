<?php

declare(strict_types=1);

namespace Tests\Feature\Seo;

use App\Models\Preacher;
use App\Models\Sermon;
use App\Models\Song;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LinkedDataTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function sermon_page_links_webpage_to_sermon_article(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'Test Sermon Title',
            'date' => '2023-10-22',
        ]);

        $year = $sermon->date->format('Y');
        $month = $sermon->date->format('m');
        $url = "/christ/sermons/{$year}/{$month}/{$sermon->slug}";

        $response = $this->get($url);

        $response->assertStatus(200);

        // Verify Sermon Article has an @id ending in #sermon
        $response->assertSee('"@id": "'.url($url).'#sermon"', false);

        // Verify WebPage has a mainEntity pointing to #sermon
        // It's tricky to assert the exact structure with assertSee, but we can check for the key/value pair
        $response->assertSee('"mainEntity": {', false);
        $response->assertSee('"@id": "'.url($url).'#sermon"', false);

        // Verify Sermon Article points to #webpage for mainEntityOfPage
        $response->assertSee('"mainEntityOfPage": {', false);
        $response->assertSee('"@id": "'.url($url).'#webpage"', false);
    }

    #[Test]
    public function preacher_page_links_webpage_to_person(): void
    {
        $preacher = Preacher::factory()->create([
            'name' => 'John Doe',
            'slug' => 'john-doe',
        ]);

        $url = "/christ/sermons/preachers/{$preacher->slug}";

        $response = $this->get($url);

        $response->assertStatus(200);

        // Verify Person has an @id ending in #person
        $response->assertSee('"@id": "'.url($url).'#person"', false);

        // Verify WebPage has a mainEntity pointing to #person
        $response->assertSee('"mainEntity": {', false);
        $response->assertSee('"@id": "'.url($url).'#person"', false);
    }

    #[Test]
    public function song_page_links_webpage_to_music_composition(): void
    {
        // Authenticate as a user to access song pages
        $this->actingAs(User::factory()->create());

        $song = Song::factory()->create([
            'title' => 'Amazing Grace',
            'slug' => 'amazing-grace',
        ]);

        $url = "/church/songs/{$song->slug}";

        $response = $this->get($url);

        $response->assertStatus(200);

        // Verify MusicComposition has an @id ending in #song
        $response->assertSee('"@id": "'.url($url).'#song"', false);

        // Verify WebPage has a mainEntity pointing to #song
        $response->assertSee('"mainEntity": {', false);
        $response->assertSee('"@id": "'.url($url).'#song"', false);
    }
}
