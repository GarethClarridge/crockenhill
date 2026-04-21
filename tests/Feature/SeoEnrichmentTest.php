<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Sermon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SeoEnrichmentTest extends TestCase
{
    use DatabaseTransactions;

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
