<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Sermon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonJsonLdTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function sermon_page_includes_enhanced_json_ld(): void
    {
        Storage::fake('local');
        $transcriptPath = 'transcripts/test-transcript.md';
        Storage::put($transcriptPath, 'This is a test transcript.');

        $sermon = Sermon::factory()->create([
            'title' => 'Enhanced JSON-LD Sermon',
            'slug' => 'enhanced-json-ld-sermon',
            'date' => '2024-03-20',
            'duration' => 3661, // 1h 1m 1s -> PT1H1M1S
            'transcript_file_path' => $transcriptPath,
            'audio_file_path' => 'audio/test.mp3',
            'video_file_path' => 'video/test.mp4',
        ]);

        // Clear cache if needed, but here we just want to ensure we hit the route
        $response = $this->get("/christ/sermons/2024/03/{$sermon->slug}");

        $response->assertStatus(200);

        $content = $response->getContent();

        // Verify Article schema
        $this->assertStringContainsString('"@type": "Article"', $content);
        $this->assertStringContainsString('"headline": "Enhanced JSON-LD Sermon"', $content);
        // We use a looser match for description because of potential escaping or slight formatting differences in the rendered output
        $this->assertStringContainsString('"description":', $content);
        $this->assertStringContainsString('Enhanced JSON-LD Sermon', $content);

        // Verify VideoObject schema
        $this->assertStringContainsString('"@type": "VideoObject"', $content);
        $this->assertStringContainsString('"duration": "PT1H1M1S"', $content);
        $this->assertStringContainsString('"transcript": "This is a test transcript."', $content);

        // Verify AudioObject schema
        $this->assertStringContainsString('"@type": "AudioObject"', $content);
        $this->assertStringContainsString('"duration": "PT1H1M1S"', $content);
        $this->assertStringContainsString('"transcript": "This is a test transcript."', $content);
        $this->assertStringContainsString('"uploadDate": "2024-03-20T00:00:00+00:00"', $content);
    }

    #[Test]
    public function sermon_page_handles_missing_optional_json_ld_fields(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'Minimal JSON-LD Sermon',
            'slug' => 'minimal-json-ld-sermon',
            'date' => '2024-03-21',
            'duration' => null,
            'transcript_file_path' => null,
            'audio_file_path' => '', // Changed from null to empty string to satisfy constraint
            'video_file_path' => null,
        ]);

        $response = $this->get("/christ/sermons/2024/03/{$sermon->slug}");

        $response->assertStatus(200);

        $content = $response->getContent();

        $this->assertStringContainsString('"@type": "Article"', $content);
        $this->assertStringNotContainsString('"@type": "VideoObject"', $content);
        $this->assertStringNotContainsString('"@type": "AudioObject"', $content);
        $this->assertStringNotContainsString('"duration"', $content);
        $this->assertStringNotContainsString('"transcript"', $content);
    }
}
