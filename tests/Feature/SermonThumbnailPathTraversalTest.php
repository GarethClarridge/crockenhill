<?php

namespace Tests\Feature;

use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SermonThumbnailPathTraversalTest extends TestCase
{
    use RefreshDatabase;

    public function test_cannot_access_files_outside_thumbnail_directory(): void
    {
        // We use the real storage for this test to see if path traversal works
        // But since we are in a sandbox, we should be careful.
        // Actually, Storage::fake() might still be susceptible if path() is used.

        Storage::fake('public');

        // Put a sensitive file in the root of the 'public' disk
        Storage::disk('public')->put('secrets.txt', 'this is a secret');

        // Create a sermon with a malicious thumbnail path
        $sermon = Sermon::factory()->create([
            'slug' => 'malicious-sermon',
            'thumbnail_file_path' => '../secrets.txt',
        ]);

        $response = $this->get("/christ/sermons/{$sermon->slug}/thumbnail");

        // It should now return 404 because of our security check
        $response->assertStatus(404);
    }

    public function test_cannot_access_audio_files_outside_expected_directory(): void
    {
        Storage::fake('public');

        // Put a sensitive file
        Storage::disk('public')->put('secrets.txt', 'this is a secret');

        // Create a sermon with a malicious audio path
        $sermon = Sermon::factory()->create([
            'slug' => 'malicious-audio-sermon',
            'audio_file_path' => '../secrets.txt',
        ]);

        $response = $this->get("/christ/sermons/{$sermon->slug}/audio");

        // It should return 404 because of our security check
        $response->assertStatus(404);
    }
}
