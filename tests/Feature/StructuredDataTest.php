<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PageArea;
use App\Models\Page;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StructuredDataTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function sermon_page_contains_json_ld_article_schema()
    {
        $sermon = Sermon::factory()->create([
            'title' => 'Test Sermon Title',
            'preacher' => 'Rev. John Doe',
            'series' => 'Gospel of Mark',
            'reference' => 'Mark 1:1-8',
            'date' => '2023-10-22',
            'thumbnail_file_path' => 'thumbnails/test_sermon.jpg',
            'meta_description' => 'A great sermon about Mark.',
        ]);

        $year = $sermon->date->format('Y');
        $month = $sermon->date->format('m');
        $url = "/christ/sermons/{$year}/{$month}/{$sermon->slug}";

        $response = $this->get($url);

        $response->assertStatus(200);

        // Check if the script tag exists
        $response->assertSee('<script type="application/ld+json">', false);

        // Extract JSON-LD and check content
        // Matching JSON_PRETTY_PRINT format: keys and values separated by ": "

        $response->assertSee('"@context": "https://schema.org"', false);
        $response->assertSee('"@type": "Article"', false);
        $response->assertSee('"headline": "Test Sermon Title"', false);
        $response->assertSee('"name": "Rev. John Doe"', false); // Author name
        // Date format might vary slightly in JSON encoding vs string, but generally consistent
        $response->assertSee('"datePublished": "2023-10-22T00:00:00+00:00"', false);
    }

    #[Test]
    public function sermon_page_contains_audio_object_schema_when_audio_present()
    {
        $sermon = Sermon::factory()->create([
            'audio_file_path' => 'sermons/audio.mp3',
            'meta_description' => 'Audio meta description',
        ]);

        $year = $sermon->date->format('Y');
        $month = $sermon->date->format('m');
        $url = "/christ/sermons/{$year}/{$month}/{$sermon->slug}";

        $response = $this->get($url);

        $response->assertStatus(200);

        $response->assertSee('"@type": "AudioObject"', false);
        $response->assertSee('"description": "Audio meta description"', false);
    }

    #[Test]
    public function sermon_page_contains_video_object_schema_when_video_present()
    {
        $sermon = Sermon::factory()->create([
            'video_file_path' => 'sermons/video.mp4',
            'thumbnail_file_path' => 'thumbnails/video_thumb.jpg',
            'meta_description' => 'Video meta description',
        ]);

        $year = $sermon->date->format('Y');
        $month = $sermon->date->format('m');
        $url = "/christ/sermons/{$year}/{$month}/{$sermon->slug}";

        $response = $this->get($url);

        $response->assertStatus(200);

        $response->assertSee('"@type": "VideoObject"', false);
        $response->assertSee('"description": "Video meta description"', false);
        $response->assertSee('"uploadDate":', false); // Value check might be separate if needed
    }

    #[Test]
    public function sermon_page_contains_breadcrumb_list_schema()
    {
        $sermon = Sermon::factory()->create([
            'title' => 'Breadcrumb Test Sermon',
            'date' => '2023-12-25',
        ]);

        $year = $sermon->date->format('Y');
        $month = $sermon->date->format('m');
        $url = "/christ/sermons/{$year}/{$month}/{$sermon->slug}";

        $response = $this->get($url);

        $response->assertStatus(200);

        // Check for BreadcrumbList
        $response->assertSee('"@type": "BreadcrumbList"', false);

        // Root / Home
        $response->assertSee('"position": 1', false);
        $response->assertSee('"name": "Home"', false);

        // Area (christ)
        $response->assertSee('"position": 2', false);
        $response->assertSee('"name": "Christ"', false);

        // Sermons (level 3)
        $response->assertSee('"position": 3', false);
        $response->assertSee('"name": "Sermons"', false);

        // Last item (usually level 4 for specific sermon)
        $response->assertSee('"name": "Breadcrumb Test Sermon"', false);
    }

    #[Test]
    public function generic_page_contains_breadcrumb_list_schema()
    {
        $page = Page::factory()->create([
            'heading' => 'About Us',
            'area' => PageArea::Church,
            'slug' => 'about-us',
        ]);

        $url = '/church/about-us';

        $response = $this->get($url);

        $response->assertStatus(200);

        // Check for BreadcrumbList
        $response->assertSee('"@type": "BreadcrumbList"', false);

        // Root / Home
        $response->assertSee('"position": 1', false);
        $response->assertSee('"name": "Home"', false);

        // Area (church)
        $response->assertSee('"position": 2', false);
        $response->assertSee('"name": "Church"', false);

        // Last item (About Us)
        $response->assertSee('"position": 3', false);
        $response->assertSee('"name": "About Us"', false);
    }
}
