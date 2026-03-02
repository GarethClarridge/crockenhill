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
}
