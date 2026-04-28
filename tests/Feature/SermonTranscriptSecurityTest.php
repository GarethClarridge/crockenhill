<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Sermon;
use App\Services\SermonTranscriptReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonTranscriptSecurityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_cannot_read_transcript_outside_expected_directory(): void
    {
        Storage::fake('public');

        Storage::disk('public')->put('secrets.txt', 'this is a secret');

        $sermon = Sermon::factory()->create([
            'slug' => 'malicious-transcript-sermon',
            'transcript_file_path' => '../secrets.txt',
        ]);

        $transcript = app(SermonTranscriptReader::class)->read($sermon);

        $this->assertNull($transcript);
    }

    #[Test]
    public function detail_page_does_not_embed_raw_transcript_inline(): void
    {
        Storage::fake('public');

        config([
            'media-processing.storage.transcript_disk' => 'public',
            'media-processing.storage.sermon_disk' => 'public',
        ]);

        $maliciousTranscript = 'Text </script><script>alert("x")</script>';
        Storage::disk('public')->put('transcripts/malicious.txt', $maliciousTranscript);

        $sermon = Sermon::factory()->create([
            'title' => 'Transcript Copy Test',
            'slug' => 'transcript-copy-test',
            'date' => '2023-10-22',
            'transcript_file_path' => 'transcripts/malicious.txt',
        ]);

        $response = $this->get(sprintf(
            '/christ/sermons/%s/%s/%s',
            $sermon->date->format('Y'),
            $sermon->date->format('m'),
            $sermon->slug
        ));

        $response->assertStatus(200);
        // Transcript content is lazy-loaded via /transcript endpoint — raw text must not appear in the initial page HTML
        $response->assertDontSee('</script><script>alert("x")</script>', false);
        $response->assertDontSee($maliciousTranscript, false);
    }

    #[Test]
    public function transcript_endpoint_escapes_html_in_rendered_output(): void
    {
        Storage::fake('public');

        config([
            'media-processing.storage.transcript_disk' => 'public',
            'media-processing.storage.sermon_disk' => 'public',
        ]);

        $maliciousTranscript = 'Text </script><script>alert("x")</script>';
        Storage::disk('public')->put('transcripts/malicious.txt', $maliciousTranscript);

        $sermon = Sermon::factory()->create([
            'slug' => 'xss-transcript-sermon',
            'transcript_file_path' => 'transcripts/malicious.txt',
        ]);

        $response = $this->get("/christ/sermons/{$sermon->slug}/transcript");

        $response->assertOk();
        $response->assertDontSee('</script><script>alert("x")</script>', false);
        // Confirms angle brackets are HTML-entity encoded in the rendered markdown output
        $response->assertSee('&lt;/script&gt;', false);
    }
}
