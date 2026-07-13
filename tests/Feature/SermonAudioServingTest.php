<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Sermon;
use App\Services\Sermon\SermonStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonAudioServingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock the storage disks
        Storage::fake('public');
        Storage::fake('do_spaces');

        // Set default disks
        Config::set('media-processing.storage.sermon_disk', 'public');
    }

    #[Test]
    public function can_serve_audio_file_locally(): void
    {
        /** @var Sermon $sermon */
        $sermon = Sermon::factory()->create([
            'slug' => 'test-sermon',
            'audio_file_path' => 'sermons/test.mp3',
        ]);

        // Create a fake audio file
        Storage::disk('public')->put('sermons/test.mp3', 'fake audio content');

        $response = $this->get("/christ/sermons/{$sermon->slug}/audio");

        $response->assertRedirect(app(SermonStorageService::class)->getPublicUrl($sermon));
    }

    #[Test]
    public function returns_404_when_sermon_has_no_audio_file_path(): void
    {
        /** @var Sermon $sermon */
        $sermon = Sermon::factory()->create([
            'slug' => 'test-sermon',
            'audio_file_path' => null,
        ]);

        $response = $this->get("/christ/sermons/{$sermon->slug}/audio");

        $response->assertStatus(404);
    }

    #[Test]
    public function returns_404_when_audio_file_does_not_exist(): void
    {
        /** @var Sermon $sermon */
        $sermon = Sermon::factory()->create([
            'slug' => 'test-sermon',
            'audio_file_path' => 'sermons/nonexistent.mp3',
        ]);

        $response = $this->get("/christ/sermons/{$sermon->slug}/audio");

        $response->assertStatus(404);
    }

    #[Test]
    public function redirects_to_cdn_for_do_spaces_storage(): void
    {
        Config::set('media-processing.storage.sermon_disk', 'do_spaces');
        Config::set('filesystems.disks.do_spaces.cdn_endpoint', 'https://cdn.example.com');

        /** @var Sermon $sermon */
        $sermon = Sermon::factory()->create([
            'slug' => 'test-sermon',
            'audio_file_path' => 'sermons/cdn-test.mp3',
        ]);

        // Create a fake audio file on do_spaces
        Storage::disk('do_spaces')->put('sermons/cdn-test.mp3', 'fake audio content');

        $response = $this->get("/christ/sermons/{$sermon->slug}/audio");

        $response->assertRedirect(app(SermonStorageService::class)->getPublicUrl($sermon));
    }

    #[Test]
    public function redirects_to_public_audio_url_for_do_spaces_without_cdn(): void
    {
        Config::set('media-processing.storage.sermon_disk', 'do_spaces');
        Config::set('filesystems.disks.do_spaces.cdn_endpoint', null);

        /** @var Sermon $sermon */
        $sermon = Sermon::factory()->create([
            'slug' => 'test-sermon',
            'audio_file_path' => 'sermons/direct-test.mp3',
        ]);

        // Create a fake audio file on do_spaces
        Storage::disk('do_spaces')->put('sermons/direct-test.mp3', 'fake audio content');

        $response = $this->get("/christ/sermons/{$sermon->slug}/audio");

        $response->assertRedirect(app(SermonStorageService::class)->getPublicUrl($sermon));
    }
}
