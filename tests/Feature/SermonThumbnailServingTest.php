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

    public function test_private_thumbnail_response_includes_no_store_cache_control(): void
    {
        $sermon = Sermon::factory()->create([
            'slug' => 'test-sermon',
            'thumbnail_file_path' => 'private/sermons/thumbnails/test-thumbnail.jpg',
        ]);

        // Create a fake thumbnail file
        Storage::disk('local')->put('private/sermons/thumbnails/test-thumbnail.jpg', 'fake image content');

        $admin = User::factory()->crockenhillAdmin()->create();
        $response = $this->actingAs($admin)->get("/christ/sermons/{$sermon->slug}/thumbnail");

        $response->assertStatus(200);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        // ETag is not sent — computing md5_file() for a no-store response is wasteful
        $this->assertNull($response->headers->get('ETag'));
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

    public function test_private_plain_thumbnail_route_serves_plain_variant_bytes(): void
    {
        $sermon = Sermon::factory()->create([
            'slug' => 'private-plain-thumbnail',
            'thumbnail_file_path' => 'private/sermons/thumbnails/primary.webp',
            'thumbnail_metadata' => [
                'plain_thumbnail_path' => 'private/sermons/thumbnails/plain.webp',
            ],
        ]);

        Storage::disk('local')->put($sermon->thumbnail_file_path, 'primary image content');
        Storage::disk('local')->put($sermon->plain_thumbnail_file_path, 'plain image content');

        $admin = User::factory()->crockenhillAdmin()->create();
        $response = $this->actingAs($admin)->get(route('sermons.thumbnail.plain', $sermon));

        $response->assertOk();
        $this->assertSame(
            'plain image content',
            file_get_contents($response->baseResponse->getFile()->getPathname()),
        );
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
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
