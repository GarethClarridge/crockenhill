<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Wiring smoke tests for the sermon-show JSON-LD (schema.org) block.
 *
 * Whether a VideoObject/AudioObject block appears is driven entirely by the
 * presenter's video_url / audio_url outputs (the Blade is a bare @if on those
 * values). Those variants — including the rejected-video and hidden-summary
 * cases — are asserted in Tests\Integration\Presenters\SermonViewPresenterTest.
 * This file only proves the schema component is wired in and emits the expected
 * schema.org type blocks when the corresponding media is present, and none when
 * it isn't.
 */
class SermonJsonLdTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function sermon_page_emits_full_json_ld_when_media_is_present(): void
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

        $response = $this->get("/christ/sermons/2024/03/{$sermon->slug}");

        $response->assertStatus(200);
        $response->assertSee('<script type="application/ld+json">', false);

        $content = $response->getContent();

        // Article + publisher + mainEntityOfPage wiring.
        $this->assertStringContainsString('"@type": "Article"', $content);
        $this->assertStringContainsString('"headline": "Enhanced JSON-LD Sermon"', $content);
        $this->assertStringContainsString('"publisher":', $content);
        $this->assertStringContainsString('"@type": "Organization"', $content);
        $this->assertStringContainsString('"name": "Crockenhill Baptist Church"', $content);
        $this->assertStringContainsString('"mainEntityOfPage":', $content);
        $this->assertStringContainsString('"@type": "WebPage"', $content);

        $logoSize = getimagesize(public_path('images/Primary.png'));
        if ($logoSize === false) {
            $this->fail('Primary logo image dimensions could not be read.');
        }

        $this->assertStringContainsString('"width": '.$logoSize[0], $content);
        $this->assertStringContainsString('"height": '.$logoSize[1], $content);

        // VideoObject + AudioObject blocks render when the presenter exposes media URLs.
        $this->assertStringContainsString('"@type": "VideoObject"', $content);
        $this->assertStringContainsString('"@type": "AudioObject"', $content);
        $this->assertStringContainsString('"duration": "PT1H1M1S"', $content);
        $this->assertStringContainsString('"transcript": "This is a test transcript."', $content);
        $this->assertStringContainsString('"uploadDate": "2024-03-20T00:00:00+00:00"', $content);

        // Speakable + articleBody.
        $this->assertStringContainsString('"speakable":', $content);
        $this->assertStringContainsString('"xpath":', $content);
        $this->assertStringContainsString('"/html/head/title"', $content);
        $this->assertStringContainsString('"articleBody": "This is a test transcript."', $content);
    }

    #[Test]
    public function sermon_page_omits_media_objects_when_no_media_is_present(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'Minimal JSON-LD Sermon',
            'slug' => 'minimal-json-ld-sermon',
            'date' => '2024-03-21',
            'duration' => null,
            'transcript_file_path' => null,
            'audio_file_path' => null,
            'video_file_path' => null,
        ]);

        $response = $this->get("/christ/sermons/2024/03/{$sermon->slug}");

        $response->assertStatus(200);

        $content = $response->getContent();

        $this->assertStringContainsString('"@type": "Article"', $content);
        $this->assertStringNotContainsString('"@type": "VideoObject"', $content);
        $this->assertStringNotContainsString('"@type": "AudioObject"', $content);
    }

    #[Test]
    public function sermon_page_never_leaks_a_hidden_summary_into_the_head(): void
    {
        $hiddenSummary = 'Hidden metadata leak summary should not be exposed.';

        $sermon = Sermon::factory()->create([
            'title' => 'Hidden Summary Sermon',
            'slug' => 'hidden-summary-sermon',
            'date' => '2024-03-22',
            'summary' => $hiddenSummary,
            'show_summary' => false,
        ]);

        $response = $this->get("/christ/sermons/2024/03/{$sermon->slug}");

        $response->assertStatus(200);
        $response->assertSee('<meta name="description"', false);
        $response->assertSee('<script type="application/ld+json">', false);

        // Verify it doesn't leak into meta description OR Article schema.
        $this->assertStringNotContainsString($hiddenSummary, (string) $response->getContent());
    }
}
