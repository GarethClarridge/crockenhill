<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Sermon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SermonThumbnailUXTest extends TestCase
{
    use DatabaseTransactions;

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
            ],
            'date' => '2026-02-19',
        ]);

        // Mock plain thumbnail file required by hasPlainThumbnail()/card route.
        Storage::disk('public')->put('thumbnails/test-plain.jpg', 'fake content');

        $response = $this->get('/christ/sermons/all');
        $response->assertStatus(200);
        $response->assertSee(app(\App\Presenters\SermonViewPresenter::class)->cardThumbnailUrl($sermon), false);
        $response->assertSee('?v=', false);
        $response->assertSee('alt="Sermon: Sermon with Thumbnail"', false);
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

        $response = $this->get('/christ/sermons/all');
        $response->assertStatus(200);
        $response->assertDontSee('/thumbnail/card', false);
    }

    public function test_sermon_card_does_not_render_livestream_badge(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'Livestream Sermon',
            'source_type' => 'livestream',
            'date' => '2026-02-19',
        ]);

        $response = $this->get('/christ/sermons/all');
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
        $response->assertSee(app(\App\Presenters\SermonViewPresenter::class)->thumbnailUrl($sermon), false);
    }
}
