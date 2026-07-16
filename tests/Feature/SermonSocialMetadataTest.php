<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Preacher;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Wiring smoke tests for the Twitter label/data pairs.
 *
 * These label/data pairs are assembled inline in SermonController and passed to
 * the x-meta-tags component, which renders them behind @if guards. There is no
 * presenter method to push these down to, so each page type keeps a single smoke
 * test proving the pairs render (and that the optional series pair is omitted
 * when absent).
 */
class SermonSocialMetadataTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function sermon_page_renders_preacher_and_series_twitter_labels(): void
    {
        $preacher = Preacher::factory()->create(['name' => 'John Owen']);
        $sermon = Sermon::factory()->create([
            'title' => 'Social Metadata Sermon',
            'slug' => 'social-metadata-sermon',
            'preacher_id' => $preacher->id,
            'preacher' => $preacher->name,
            'series' => 'Great Doctrines',
            'date' => '2024-03-15',
        ]);

        $response = $this->followingRedirects()->get("/christ/sermons/2024/03/{$sermon->slug}");

        $response->assertStatus(200);
        $response->assertSee('twitter:label1', false);
        $response->assertSee('Preacher', false);
        $response->assertSee('twitter:data1', false);
        $response->assertSee('John Owen', false);
        $response->assertSee('twitter:label2', false);
        $response->assertSee('Series', false);
        $response->assertSee('twitter:data2', false);
        $response->assertSee('Great Doctrines', false);
    }

    #[Test]
    public function sermon_page_omits_series_label_when_no_series_is_present(): void
    {
        $preacher = Preacher::factory()->create(['name' => 'Charles Spurgeon']);
        $sermon = Sermon::factory()->create([
            'title' => 'No Series Sermon',
            'slug' => 'no-series-sermon',
            'preacher_id' => $preacher->id,
            'preacher' => $preacher->name,
            'series' => null,
            'date' => '2024-03-15',
        ]);

        $response = $this->followingRedirects()->get("/christ/sermons/2024/03/{$sermon->slug}");

        $response->assertStatus(200);
        $response->assertSee('twitter:label1', false);
        $response->assertSee('Preacher', false);
        $response->assertDontSee('twitter:label2');
    }

    #[Test]
    public function preacher_page_renders_sermon_count_twitter_label(): void
    {
        $preacher = Preacher::factory()->create(['name' => 'Charles Spurgeon']);
        Sermon::factory()->count(5)->create([
            'preacher_id' => $preacher->id,
            'preacher' => $preacher->name,
        ]);

        $response = $this->get("/christ/sermons/preachers/{$preacher->slug}");

        $response->assertStatus(200);
        $response->assertSee('twitter:label1', false);
        $response->assertSee('Sermons', false);
        $response->assertSee('twitter:data1', false);
        $response->assertSee('5', false);
    }
}
