<?php

namespace Tests\Feature\Security;

use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AbsolutePathTraversalTest extends TestCase
{
    use RefreshDatabase;

    public function test_cannot_access_absolute_paths_in_thumbnail(): void
    {
        Storage::fake('public');

        // Create a sermon with a malicious absolute thumbnail path
        $sermon = Sermon::factory()->create([
            'slug' => 'malicious-sermon',
            'thumbnail_file_path' => '/etc/passwd',
        ]);

        $response = $this->get("/christ/sermons/{$sermon->slug}/thumbnail");

        // It should return 404 because of our security check
        $response->assertStatus(404);
    }

    public function test_cannot_access_absolute_paths_in_audio(): void
    {
        Storage::fake('public');

        // Create a sermon with a malicious absolute audio path
        $sermon = Sermon::factory()->create([
            'slug' => 'malicious-audio-sermon',
            'audio_file_path' => '/etc/passwd',
        ]);

        $response = $this->get("/christ/sermons/{$sermon->slug}/audio");

        // It should return 404 because of our security check
        $response->assertStatus(404);
    }

    public function test_cannot_access_absolute_paths_in_video(): void
    {
        Storage::fake('public');

        // Create a sermon with a malicious absolute video path
        $sermon = Sermon::factory()->create([
            'slug' => 'malicious-video-sermon',
            'video_file_path' => '/etc/passwd',
        ]);

        $response = $this->get("/christ/sermons/{$sermon->slug}/video");

        // It should return 404 because of our security check
        $response->assertStatus(404);
    }

    public function test_cannot_access_windows_absolute_paths(): void
    {
        Storage::fake('public');

        // Create a sermon with a malicious absolute windows path
        $sermon = Sermon::factory()->create([
            'slug' => 'malicious-windows-sermon',
            'audio_file_path' => 'C:\\Windows\\win.ini',
        ]);

        $response = $this->get("/christ/sermons/{$sermon->slug}/audio");

        // It should return 404 because of our security check
        $response->assertStatus(404);
    }
}
