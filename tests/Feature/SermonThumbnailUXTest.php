<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Sermon;
use App\Presenters\SermonViewPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SermonThumbnailUXTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_sermon_card_renders_thumbnail_when_available(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'Sermon with Thumbnail',
            'slug' => 'sermon-with-thumbnail',
            'thumbnail_file_path' => 'thumbnails/test-overlay.jpg',
            'thumbnail_metadata' => [
                'plain_thumbnail_path' => 'thumbnails/test-plain.jpg',
                'card_thumbnail_path' => 'thumbnails/test-card.jpg',
            ],
            'date' => '2026-02-19',
        ]);

        // Listing cards should use the bare frame, not the generated card/person variant.
        Storage::disk('public')->put('thumbnails/test-plain.jpg', 'fake content');
        Storage::disk('public')->put('thumbnails/test-card.jpg', 'fake content');

        $response = $this->get('/christ/sermons');
        $response->assertStatus(200);
        $response->assertSee(app(SermonViewPresenter::class)->plainThumbnailUrl($sermon), false);
        $response->assertDontSee(app(SermonViewPresenter::class)->cardThumbnailUrl($sermon), false);
        $response->assertSee('?v=', false);
        // The thumbnail image is decorative; the overlaid heading provides the card's accessible name.
        $response->assertSee('role="presentation"', false);
        $response->assertSee('Sermon with Thumbnail', false);
    }

    public function test_sermon_card_does_not_render_thumbnail_when_only_overlay_exists(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'Overlay Only Sermon',
            'slug' => 'overlay-only-sermon',
            'thumbnail_file_path' => 'thumbnails/overlay-only.jpg',
            'thumbnail_metadata' => null,
            'date' => '2026-02-19',
        ]);

        Storage::disk('public')->put('thumbnails/overlay-only.jpg', 'fake content');

        $response = $this->get('/christ/sermons');
        $response->assertStatus(200);
        $response->assertDontSee('/thumbnail/card', false);
    }

    public function test_sermon_card_includes_a_fallback_title_when_thumbnail_file_is_missing(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'Missing Thumbnail Sermon',
            'slug' => 'missing-thumbnail-sermon',
            'thumbnail_file_path' => 'thumbnails/missing-overlay.jpg',
            'thumbnail_metadata' => [
                'plain_thumbnail_path' => 'thumbnails/missing-plain.jpg',
            ],
            'date' => '2026-02-19',
        ]);

        $response = $this->get('/christ/sermons');

        $response->assertStatus(200);
        $response->assertSee(app(SermonViewPresenter::class)->plainThumbnailUrl($sermon), false);
        $response->assertSee('data-sermon-card-thumbnail', false);
        $response->assertSee('data-sermon-card-title-fallback', false);
        $response->assertSee("onerror=\"this.onerror=null; const card = this.closest('[data-sermon-card]'); card?.querySelector('[data-sermon-card-thumbnail]')?.remove(); card?.querySelector('[data-sermon-card-title-fallback]')?.classList.remove('hidden');\"", false);
    }

    public function test_sermon_card_does_not_render_livestream_badge(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'Livestream Sermon',
            'source_type' => 'livestream',
            'date' => '2026-02-19',
        ]);

        $response = $this->get('/christ/sermons');
        $response->assertStatus(200);
        $response->assertDontSee('animate-pulse');
    }

    public function test_sermon_page_renders_thumbnail_when_no_video(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'Sermon Page Thumbnail',
            'slug' => 'sermon-page-thumbnail',
            'thumbnail_file_path' => 'thumbnails/page.jpg',
            'video_file_path' => null,
            'date' => '2026-02-19',
        ]);

        // Mock the file existence
        Storage::disk('public')->put('thumbnails/page.jpg', 'fake content');

        $response = $this->followingRedirects()->get("/christ/sermons/{$sermon->slug}");
        $response->assertStatus(200);
        $response->assertSee(app(SermonViewPresenter::class)->thumbnailUrl($sermon), false);
    }
}
