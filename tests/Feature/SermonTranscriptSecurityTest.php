<?php

namespace Tests\Feature;

use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SermonTranscriptSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_cannot_read_transcript_outside_expected_directory(): void
    {
        Storage::fake('public');

        // Put a sensitive file
        Storage::disk('public')->put('secrets.txt', 'this is a secret');

        // Create a sermon with a malicious transcript path
        $sermon = Sermon::factory()->create([
            'slug' => 'malicious-transcript-sermon',
            'transcript_file_path' => '../secrets.txt',
        ]);

        // Access the transcript attribute
        $transcript = $sermon->transcript;

        // It should be null because of the path traversal check
        $this->assertNull($transcript);
    }

    public function test_transcript_copy_payload_is_safely_encoded(): void
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
        $response->assertSee('Copy Transcript');
        $response->assertDontSee('</script><script>alert("x")</script>', false);
        $response->assertSee('\u003C\/script\u003E\u003Cscript\u003Ealert(\u0022x\u0022)\u003C\/script\u003E', false);
    }
}
