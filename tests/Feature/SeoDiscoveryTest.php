<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Preacher;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SeoDiscoveryTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function sermon_listing_pages_contain_podcast_rss_links(): void
    {
        $preacher = Preacher::factory()->create(['name' => 'SEO Preacher', 'slug' => 'seo-preacher']);
        Sermon::factory()->create([
            'title' => 'SEO Sermon',
            'slug' => 'seo-sermon',
            'series' => 'SEO Series',
            'preacher_id' => $preacher->id,
        ]);

        $urls = [
            '/christ/sermons/all',
            '/christ/sermons/preachers',
            '/christ/sermons/preachers/seo-preacher',
            '/christ/sermons/series',
            '/christ/sermons/series/seo-series',
            '/christ/sermons/morning',
            '/christ/sermons/evening',
        ];

        foreach ($urls as $url) {
            $response = $this->get($url);
            $response->assertStatus(200);
            $response->assertSee('type="application/rss+xml"', false);

            if ($url !== '/christ/sermons/evening') {
                $response->assertSee('title="Sunday Morning Sermons"', false);
            }

            if ($url !== '/christ/sermons/morning') {
                $response->assertSee('title="Sunday Evening Sermons"', false);
            }
        }
    }

    #[Test]
    public function sermon_item_list_structured_data_contains_media_objects(): void
    {
        Sermon::factory()->create([
            'title' => 'Media Sermon',
            'slug' => 'media-sermon',
            'audio_file_path' => 'audio/test.mp3',
            'video_file_path' => 'video/test.mp4',
            'duration' => 1800,
        ]);

        $response = $this->get('/christ/sermons/all');
        $response->assertStatus(200);

        $content = $response->getContent();
        $this->assertStringContainsString('"@type": "AudioObject"', $content);
        $this->assertStringContainsString('"@type": "VideoObject"', $content);
        $this->assertStringContainsString('"duration": "PT30M"', $content);
    }
}
