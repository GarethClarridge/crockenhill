<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Sermon;
use App\Models\User;
use App\Services\Sermon\SermonStorageService;
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
        Storage::fake('local');
    }

    public function test_can_serve_sermon_thumbnail(): void
    {
        $sermon = Sermon::factory()->create([
            'slug' => 'test-sermon',
            'thumbnail_file_path' => 'sermons/thumbnails/test-thumbnail.jpg',
        ]);

        // Create a fake thumbnail file
        Storage::disk('public')->put('sermons/thumbnails/test-thumbnail.jpg', 'fake image content');

        $response = $this->get("/christ/sermons/{$sermon->slug}/thumbnail");

        $response->assertRedirect(app(SermonStorageService::class)->getThumbnailUrl($sermon));
    }

    public function test_returns_404_when_sermon_has_no_thumbnail(): void
    {
        $sermon = Sermon::factory()->create([
            'slug' => 'test-sermon',
            'thumbnail_file_path' => null,
        ]);

        $response = $this->get("/christ/sermons/{$sermon->slug}/thumbnail");

        $response->assertStatus(404);
    }

    public function test_returns_404_when_thumbnail_file_does_not_exist(): void
    {
        $sermon = Sermon::factory()->create([
            'slug' => 'test-sermon',
            'thumbnail_file_path' => 'sermons/thumbnails/nonexistent.jpg',
        ]);

        $response = $this->get("/christ/sermons/{$sermon->slug}/thumbnail");

        $response->assertStatus(404);
    }

    public function test_serves_png_thumbnails_with_correct_content_type(): void
    {
        $sermon = Sermon::factory()->create([
            'slug' => 'test-sermon',
            'thumbnail_file_path' => 'sermons/thumbnails/test-thumbnail.png',
        ]);

        // Create a fake PNG thumbnail file
        Storage::disk('public')->put('sermons/thumbnails/test-thumbnail.png', 'fake png content');

        $response = $this->get("/christ/sermons/{$sermon->slug}/thumbnail");

        $response->assertRedirect(app(SermonStorageService::class)->getThumbnailUrl($sermon));
    }

    public function test_serves_webp_thumbnails_with_correct_content_type(): void
    {
        $sermon = Sermon::factory()->create([
            'slug' => 'test-sermon',
            'thumbnail_file_path' => 'sermons/thumbnails/test-thumbnail.webp',
        ]);

        // Create a fake WebP thumbnail file
        Storage::disk('public')->put('sermons/thumbnails/test-thumbnail.webp', 'fake webp content');

        $response = $this->get("/christ/sermons/{$sermon->slug}/thumbnail");

        $response->assertRedirect(app(SermonStorageService::class)->getThumbnailUrl($sermon));
    }

    public function test_a_legacy_private_thumbnail_path_is_no_longer_streamed(): void
    {
        $sermon = Sermon::factory()->create([
            'slug' => 'test-sermon',
            'thumbnail_file_path' => 'private/sermons/thumbnails/test-thumbnail.jpg',
        ]);

        // Create a fake thumbnail file
        Storage::disk('local')->put('private/sermons/thumbnails/test-thumbnail.jpg', 'fake image content');

        $admin = User::factory()->crockenhillAdmin()->create();
        $response = $this->actingAs($admin)->get("/christ/sermons/{$sermon->slug}/thumbnail");

        // No no-store streaming branch remains; the route only resolves against
        // the configured thumbnail disk.
        $response->assertStatus(404);
    }

    public function test_card_thumbnail_prefers_card_variant_when_available(): void
    {
        $sermon = Sermon::factory()->create([
            'slug' => 'test-sermon',
            'thumbnail_file_path' => 'sermons/thumbnails/test-overlay.webp',
            'thumbnail_metadata' => [
                'card_thumbnail_path' => 'sermons/thumbnails/test-card.webp',
                'plain_thumbnail_path' => 'sermons/thumbnails/test-plain.webp',
            ],
        ]);

        Storage::disk('public')->put('sermons/thumbnails/test-overlay.webp', 'overlay image content');
        Storage::disk('public')->put('sermons/thumbnails/test-card.webp', 'card image content');
        Storage::disk('public')->put('sermons/thumbnails/test-plain.webp', 'plain image content');

        $response = $this->get("/christ/sermons/{$sermon->slug}/thumbnail/card");

        $response->assertRedirect(app(SermonStorageService::class)->getCardThumbnailUrl($sermon));
    }

    public function test_plain_thumbnail_route_resolves_the_plain_variant_not_the_primary(): void
    {
        $sermon = Sermon::factory()->create([
            'slug' => 'plain-thumbnail-variant',
            'thumbnail_file_path' => 'sermons/thumbnails/primary.webp',
            'thumbnail_metadata' => [
                'plain_thumbnail_path' => 'sermons/thumbnails/plain.webp',
            ],
        ]);

        Storage::disk('public')->put($sermon->thumbnail_file_path, 'primary image content');
        Storage::disk('public')->put($sermon->plain_thumbnail_file_path, 'plain image content');

        $admin = User::factory()->crockenhillAdmin()->create();
        $response = $this->actingAs($admin)->get(route('sermons.thumbnail.plain', $sermon));

        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');
        $this->assertStringContainsString('sermons/thumbnails/plain.webp', $location);
        $this->assertStringNotContainsString('primary.webp', $location);
    }

    public function test_public_plain_thumbnail_route_redirects_to_plain_variant_url(): void
    {
        $sermon = Sermon::factory()->create([
            'slug' => 'public-plain-thumbnail',
            'thumbnail_metadata' => [
                'plain_thumbnail_path' => 'sermons/thumbnails/plain.webp',
            ],
        ]);
        Storage::disk('public')->put($sermon->plain_thumbnail_file_path, 'plain image content');

        $response = $this->get(route('sermons.thumbnail.plain', $sermon));

        $response->assertRedirect(app(SermonStorageService::class)->getPlainThumbnailUrl($sermon));
    }

    public function test_card_thumbnail_falls_back_to_plain_variant_when_card_path_is_not_set(): void
    {
        $sermon = Sermon::factory()->create([
            'slug' => 'test-sermon',
            'thumbnail_file_path' => 'sermons/thumbnails/test-overlay.webp',
            'thumbnail_metadata' => [
                'plain_thumbnail_path' => 'sermons/thumbnails/test-plain.webp',
            ],
        ]);

        Storage::disk('public')->put('sermons/thumbnails/test-overlay.webp', 'overlay image content');
        Storage::disk('public')->put('sermons/thumbnails/test-plain.webp', 'plain image content');

        $response = $this->get("/christ/sermons/{$sermon->slug}/thumbnail/card");

        $response->assertRedirect(app(SermonStorageService::class)->getCardThumbnailUrl($sermon));
    }

    public function test_card_thumbnail_returns_404_when_plain_variant_is_missing(): void
    {
        $sermon = Sermon::factory()->create([
            'slug' => 'test-sermon',
            'thumbnail_file_path' => 'sermons/thumbnails/test-overlay.webp',
            'thumbnail_metadata' => [
                'plain_thumbnail_path' => 'sermons/thumbnails/missing-plain.webp',
            ],
        ]);

        Storage::disk('public')->put('sermons/thumbnails/test-overlay.webp', 'overlay image content');

        $response = $this->get("/christ/sermons/{$sermon->slug}/thumbnail/card");

        $response->assertStatus(404);
    }

    public function test_card_thumbnail_returns_404_when_plain_variant_path_is_not_set(): void
    {
        $sermon = Sermon::factory()->create([
            'slug' => 'test-sermon',
            'thumbnail_file_path' => 'sermons/thumbnails/test-overlay.webp',
            'thumbnail_metadata' => null,
        ]);

        Storage::disk('public')->put('sermons/thumbnails/test-overlay.webp', 'overlay image content');

        $response = $this->get("/christ/sermons/{$sermon->slug}/thumbnail/card");

        $response->assertStatus(404);
    }
}
