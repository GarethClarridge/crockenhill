<?php

namespace Tests\Feature;

use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StructuredDataTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
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

    /** @test */
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

    /** @test */
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
}
