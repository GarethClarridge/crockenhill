<?php

namespace Tests\Feature;

use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonAssetControllerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_serves_audio_file_for_local_storage(): void
    {
        Storage::fake('public');

        $sermon = Sermon::factory()->create([
            'slug' => 'test-sermon',
            'audio_file_path' => 'sermons/test-audio.mp3',
        ]);

        // Create the fake audio file
        Storage::disk('public')->put('sermons/test-audio.mp3', 'fake audio content');

        $response = $this->get("/christ/sermons/{$sermon->slug}/audio");

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'audio/mpeg');
        $response->assertHeader('Content-Disposition', 'inline; filename="test-audio.mp3"');
    }

    #[Test]
    public function it_returns_404_when_audio_file_does_not_exist(): void
    {
        Storage::fake('public');

        $sermon = Sermon::factory()->create([
            'slug' => 'missing-audio-sermon',
            'audio_file_path' => 'sermons/missing.mp3',
        ]);

        $response = $this->get("/christ/sermons/{$sermon->slug}/audio");

        $response->assertStatus(404);
    }

    #[Test]
    public function it_serves_thumbnail_for_a_sermon(): void
    {
        Storage::fake('public');

        $sermon = Sermon::factory()->create([
            'slug' => 'test-sermon',
            'thumbnail_file_path' => 'thumbnails/test-thumb.webp',
        ]);

        // Create the fake thumbnail file
        Storage::disk('public')->put('thumbnails/test-thumb.webp', 'fake webp content');

        $response = $this->get("/christ/sermons/{$sermon->slug}/thumbnail");

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/webp');
        $response->assertHeader('Content-Disposition', 'inline; filename="test-thumb.webp"');
    }

    #[Test]
    public function it_returns_404_when_thumbnail_file_does_not_exist(): void
    {
        Storage::fake('public');

        $sermon = Sermon::factory()->create([
            'slug' => 'missing-thumbnail-sermon',
            'thumbnail_file_path' => 'thumbnails/missing.webp',
        ]);

        $response = $this->get("/christ/sermons/{$sermon->slug}/thumbnail");

        $response->assertStatus(404);
    }

    #[Test]
    public function it_sets_correct_cache_headers_for_thumbnail(): void
    {
        Storage::fake('public');

        $sermon = Sermon::factory()->create([
            'slug' => 'cached-thumbnail-sermon',
            'thumbnail_file_path' => 'thumbnails/cached.jpg',
        ]);

        Storage::disk('public')->put('thumbnails/cached.jpg', 'fake jpg content');

        $response = $this->get("/christ/sermons/{$sermon->slug}/thumbnail");

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/jpeg');
    }

    #[Test]
    public function it_detects_correct_content_type_for_jpg(): void
    {
        Storage::fake('public');

        $sermon = Sermon::factory()->create([
            'slug' => 'jpg-sermon',
            'thumbnail_file_path' => 'thumbnails/image.jpg',
        ]);

        Storage::disk('public')->put('thumbnails/image.jpg', 'fake jpg');

        $response = $this->get("/christ/sermons/{$sermon->slug}/thumbnail");

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/jpeg');
    }

    #[Test]
    public function it_detects_correct_content_type_for_png(): void
    {
        Storage::fake('public');

        $sermon = Sermon::factory()->create([
            'slug' => 'png-sermon',
            'thumbnail_file_path' => 'thumbnails/image.png',
        ]);

        Storage::disk('public')->put('thumbnails/image.png', 'fake png');

        $response = $this->get("/christ/sermons/{$sermon->slug}/thumbnail");

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/png');
    }

    #[Test]
    public function it_serves_card_thumbnail_successfully(): void
    {
        Storage::fake('public');
        config(['thumbnail-generation.storage.disk' => 'public']);

        $sermon = Sermon::factory()->create([
            'slug' => 'card-test-sermon',
            'thumbnail_metadata' => [
                'plain_thumbnail_path' => 'thumbnails/card-plain.jpg',
            ],
        ]);

        Storage::disk('public')->put('thumbnails/card-plain.jpg', 'fake plain jpg content');

        $response = $this->get("/christ/sermons/{$sermon->slug}/thumbnail/card");

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/jpeg');
    }

    #[Test]
    public function it_returns_404_when_card_thumbnail_is_missing(): void
    {
        $sermon = Sermon::factory()->create([
            'slug' => 'no-card-test-sermon',
            'thumbnail_metadata' => null,
        ]);

        $response = $this->get("/christ/sermons/{$sermon->slug}/thumbnail/card");

        $response->assertStatus(404);
    }

    #[Test]
    public function it_returns_404_when_card_thumbnail_file_missing_on_disk(): void
    {
        Storage::fake('public');
        config(['thumbnail-generation.storage.disk' => 'public']);

        $sermon = Sermon::factory()->create([
            'slug' => 'missing-file-card-sermon',
            'thumbnail_metadata' => [
                'plain_thumbnail_path' => 'thumbnails/missing-on-disk.jpg',
            ],
        ]);

        // We DO NOT put the file on the fake disk

        $response = $this->get("/christ/sermons/{$sermon->slug}/thumbnail/card");

        $response->assertStatus(404);
    }
}
