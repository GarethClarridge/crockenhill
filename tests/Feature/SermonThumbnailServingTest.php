<?php

namespace Tests\Feature;

use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SermonThumbnailServingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock the storage disk
        Storage::fake('public');
    }

    public function test_can_serve_sermon_thumbnail(): void
    {
        $sermon = Sermon::factory()->create([
            'slug' => 'test-sermon',
            'thumbnail_path' => 'sermons/thumbnails/test-thumbnail.jpg',
        ]);

        // Create a fake thumbnail file
        Storage::disk('public')->put('sermons/thumbnails/test-thumbnail.jpg', 'fake image content');

        $response = $this->get("/christ/sermons/{$sermon->slug}/thumbnail");

        $response->assertStatus(200)
            ->assertHeader('Content-Type', 'image/jpeg');

        // Check that Cache-Control header contains both values (order may vary)
        $cacheControl = $response->headers->get('Cache-Control');
        $this->assertStringContainsString('public', $cacheControl);
        $this->assertStringContainsString('max-age=86400', $cacheControl);
    }

    public function test_returns_404_when_sermon_has_no_thumbnail(): void
    {
        $sermon = Sermon::factory()->create([
            'slug' => 'test-sermon',
            'thumbnail_path' => null,
        ]);

        $response = $this->get("/christ/sermons/{$sermon->slug}/thumbnail");

        $response->assertStatus(404);
    }

    public function test_returns_404_when_thumbnail_file_does_not_exist(): void
    {
        $sermon = Sermon::factory()->create([
            'slug' => 'test-sermon',
            'thumbnail_path' => 'sermons/thumbnails/nonexistent.jpg',
        ]);

        $response = $this->get("/christ/sermons/{$sermon->slug}/thumbnail");

        $response->assertStatus(404);
    }

    public function test_serves_png_thumbnails_with_correct_content_type(): void
    {
        $sermon = Sermon::factory()->create([
            'slug' => 'test-sermon',
            'thumbnail_path' => 'sermons/thumbnails/test-thumbnail.png',
        ]);

        // Create a fake PNG thumbnail file
        Storage::disk('public')->put('sermons/thumbnails/test-thumbnail.png', 'fake png content');

        $response = $this->get("/christ/sermons/{$sermon->slug}/thumbnail");

        $response->assertStatus(200)
            ->assertHeader('Content-Type', 'image/png');
    }

    public function test_serves_webp_thumbnails_with_correct_content_type(): void
    {
        $sermon = Sermon::factory()->create([
            'slug' => 'test-sermon',
            'thumbnail_path' => 'sermons/thumbnails/test-thumbnail.webp',
        ]);

        // Create a fake WebP thumbnail file
        Storage::disk('public')->put('sermons/thumbnails/test-thumbnail.webp', 'fake webp content');

        $response = $this->get("/christ/sermons/{$sermon->slug}/thumbnail");

        $response->assertStatus(200)
            ->assertHeader('Content-Type', 'image/webp');
    }

    public function test_thumbnail_response_includes_caching_headers(): void
    {
        $sermon = Sermon::factory()->create([
            'slug' => 'test-sermon',
            'thumbnail_path' => 'sermons/thumbnails/test-thumbnail.jpg',
        ]);

        // Create a fake thumbnail file
        Storage::disk('public')->put('sermons/thumbnails/test-thumbnail.jpg', 'fake image content');

        $response = $this->get("/christ/sermons/{$sermon->slug}/thumbnail");

        $response->assertStatus(200);

        // Check that Cache-Control header contains both values (order may vary)
        $cacheControl = $response->headers->get('Cache-Control');
        $this->assertStringContainsString('public', $cacheControl);
        $this->assertStringContainsString('max-age=86400', $cacheControl);

        // Check that caching headers are present
        $this->assertNotNull($response->headers->get('ETag'));
        $this->assertNotNull($response->headers->get('Last-Modified'));
    }
}
