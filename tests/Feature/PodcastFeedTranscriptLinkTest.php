<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SermonService;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PodcastFeedTranscriptLinkTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function podcast_feed_contains_resolvable_transcript_url(): void
    {
        // 1. Create a sermon with a transcript in the morning service
        $sermon = Sermon::factory()->create([
            'service' => SermonService::Morning->value,
            'transcript_file_path' => 'transcripts/test-sermon.txt',
            'audio_file_path' => 'sermons/test-sermon.mp3',
        ]);

        // 2. Fetch the morning podcast feed
        $response = $this->get('/christ/sermons/morning/feed');
        $response->assertStatus(200);

        $xml = $response->getContent();

        // 3. Extract the transcript URL from the feed
        // <podcast:transcript url="..." type="text/html" />
        if (! preg_match('/<podcast:transcript url="([^"]+)"/', $xml, $matches)) {
            $this->fail('Transcript URL not found in podcast feed');
        }

        $transcriptUrl = $matches[1];
        $path = parse_url($transcriptUrl, PHP_URL_PATH);

        // 4. Assert that the path matches the sermons.transcript route
        // We can do this by trying to resolve it
        $route = Route::getRoutes()->match(request()->create($path, 'GET'));

        $this->assertEquals('sermons.transcript', $route->getName(), "The URL $transcriptUrl does not match the expected 'sermons.transcript' route.");
    }
}
